<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmPipeline;
use App\Models\CrmSetting;
use App\Models\CrmWebForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns enquiries into CRM work: web forms, store contact messages, quote
 * requests, property inquiries, vehicle leads, website bookings, unknown WhatsApp numbers (→ leads) and portal
 * quotation requests (→ deals for the customer).
 *
 * A returning person (same email / phone as an open lead) doesn't create a
 * duplicate: the enquiry is added to that lead as a note. An existing
 * customer gets the note (or a deal) on their customer record instead.
 * New leads are owned through the assignment rules, get a follow-up task and
 * their owner is notified.
 */
class CrmCaptureService
{
    public const LABELS = [
        'web_form' => 'Web form',
        'store_message' => 'Store contact message',
        'quote_request' => 'Quote request',
        'property_inquiry' => 'Property inquiry',
        'portal_request' => 'Portal quotation request',
        'whatsapp' => 'WhatsApp message',
        'vehicle_lead' => 'Vehicle enquiry',
        'booking' => 'Website booking',
    ];

    /** Default lead source per channel (created on first use, then remembered in crm_settings.capture_sources). */
    private const SOURCE_NAMES = [
        'web_form' => 'Website form',
        'store_message' => 'Online store',
        'quote_request' => 'Quote request',
        'property_inquiry' => 'Property inquiry',
        'whatsapp' => 'WhatsApp',
        'vehicle_lead' => 'Car dealership website',
        'booking' => 'Booking website',
    ];

    public function __construct(
        private CrmLeadService $leads,
        private CrmDealService $deals,
        private CrmAssignmentService $assignment,
    ) {
    }

    /**
     * Called from store / portal / WhatsApp code: runs $fn only when the CRM
     * is installed, switched on and the capture switch is on — and never lets
     * a CRM problem break the visitor's request.
     */
    public static function hook(string $flag, callable $fn): void
    {
        try {
            if (! self::enabled() || ! CrmSetting::current()->{$flag}) {
                return;
            }
            $fn(app(self::class));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function enabled(): bool
    {
        if (! Schema::hasTable('crm_settings') || ! Schema::hasColumn('crm_settings', 'capture_store_messages')) {
            return false;
        }
        $raw = DB::table('settings')->whereNull('deleted_at')->value('module_flags');
        $flags = $raw ? json_decode($raw, true) : null;
        if (is_array($flags) && array_key_exists('crm', $flags) && $flags['crm'] === false) {
            return false;
        }

        // SaaS: the tenant's plan must include the CRM.
        return self::planAllows('crm');
    }

    /** Whether the active subscription's plan carries a feature key. */
    public static function planAllows(string $feature): bool
    {
        try {
            $plan = app(\App\Services\TenantLimitsService::class)->getActivePlan();

            return $plan ? (bool) $plan->hasFeature($feature) : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param  array  $d  name | first_name, last_name, email, phone, company_name, job_title, website, city, country,
     *                    estimated_value, subject, message, extra (label => value)
     * @param  array  $o  form (CrmWebForm), source_id, status_id, owner_id, tag_ids, ref_id, client_id, note (bool, default true)
     * @return array{status: string, lead_id: ?int, client_id: ?int, deal_id: ?int}
     */
    public function capture(string $via, array $d, array $o = []): array
    {
        $d = $this->clean($d);
        $settings = CrmSetting::current();
        $note = $this->noteText($via, $d);
        $withNote = $o['note'] ?? true;

        // 1. Someone we already talk to as a lead: add to that lead.
        $existing = $this->openLeadFor($d['email'], $d['phone']);
        if ($existing && empty($o['client_id'])) {
            DB::transaction(function () use ($existing, $via, $note, $withNote, $o) {
                if ($withNote) {
                    $this->addNote(['crm_lead_id' => $existing->id, 'owner_id' => $existing->owner_id], $note);
                }
                $this->leads->log($existing, 'captured', null, ['via' => $via, 'again' => true, 'form' => optional($o['form'] ?? null)->name]);
                $changes = ['last_activity_at' => now()];
                // A lost lead getting in touch again is interested again: back to the open pipeline.
                if ($existing->statusKind() === 'lost' && ($open = CrmLeadStatus::defaultId())) {
                    $changes += ['crm_lead_status_id' => $open, 'crm_lost_reason_id' => null, 'lost_note' => null, 'lost_at' => null];
                    $this->leads->log($existing, 'reopened', self::LABELS[$via] ?? $via);
                }
                CrmLead::whereKey($existing->id)->update($changes);
            });
            CrmScoringService::refresh($existing->id);
            $this->notifyOwner($existing->owner_id, $existing, $via, true);

            return ['status' => 'merged', 'lead_id' => $existing->id, 'client_id' => $existing->client_id, 'deal_id' => null];
        }

        // 2. An existing customer: the enquiry goes on the customer record.
        $clientId = $o['client_id'] ?? $this->clientFor($d['email'], $d['phone']);
        if ($clientId) {
            if ($via === 'quote_request') {
                $deal = $this->captureDeal($via, (int) $clientId, $note['subject'], $note['body'], ['ref_id' => $o['ref_id'] ?? null]);

                return ['status' => 'client', 'lead_id' => null, 'client_id' => (int) $clientId, 'deal_id' => $deal->id];
            }
            if ($withNote) {
                $this->addNote(['client_id' => (int) $clientId, 'owner_id' => $this->accountOwner((int) $clientId)], $note);
            }

            return ['status' => 'client', 'lead_id' => null, 'client_id' => (int) $clientId, 'deal_id' => null];
        }

        // 3. Someone new: a lead.
        $form = $o['form'] ?? null;
        $lead = DB::transaction(function () use ($via, $d, $o, $form, $settings, $note, $withNote) {
            $name = CrmLead::displayName($d);
            if ($name === '') {
                $name = $d['email'] ?: ($d['phone'] ?: self::LABELS[$via] ?? 'New lead');
            }
            $lead = new CrmLead([
                'lead_number' => $this->leads->nextNumber(),
                'type' => $d['type'],
                'name' => mb_substr($name, 0, 191),
                'first_name' => $d['first_name'],
                'last_name' => $d['last_name'],
                'company_name' => $d['company_name'],
                'job_title' => $d['job_title'],
                'email' => $d['email'],
                'phone' => $d['phone'],
                'phone_normalized' => CrmLead::normalizePhone($d['phone']),
                'website' => $d['website'],
                'city' => $d['city'],
                'country' => $d['country'],
                'crm_lead_source_id' => $o['source_id'] ?? $this->sourceFor($via),
                'crm_lead_status_id' => $this->openStatus($o['status_id'] ?? null),
                'rating' => 'warm',
                'estimated_value' => $d['estimated_value'],
                'description' => $withNote ? $note['body'] : null,
                'captured_via' => $via,
                'crm_web_form_id' => optional($form)->id,
                'capture_ref_id' => $o['ref_id'] ?? null,
                'last_activity_at' => now(),
            ]);

            $rule = null;
            $owner = $this->activeUser($o['owner_id'] ?? null);
            if (! $owner) {
                [$owner, $rule] = $this->assignment->pick($lead, $via);
            }
            $lead->owner_id = $owner;
            $lead->save();

            if (! empty($o['tag_ids'])) {
                $this->leads->syncTags($lead, (array) $o['tag_ids']);
            }
            $this->leads->log($lead, 'captured', null, array_filter([
                'via' => $via, 'form' => optional($form)->name, 'rule' => optional($rule)->name,
            ]));
            $this->followUp(['crm_lead_id' => $lead->id, 'owner_id' => $lead->owner_id], $via, $settings);

            return $lead;
        });

        $this->notifyOwner($lead->owner_id, $lead, $via, false);

        return ['status' => 'created', 'lead_id' => $lead->id, 'client_id' => null, 'deal_id' => null];
    }

    /** A deal for an existing customer (portal / store quote requests). */
    public function captureDeal(string $via, int $clientId, string $title, ?string $body, array $o = []): CrmDeal
    {
        $settings = CrmSetting::current();
        $client = Client::find($clientId);
        $pipeline = CrmPipeline::defaultPipeline();
        $stage = $pipeline ? $pipeline->stages()->orderBy('sort_order')->first() : null;
        abort_unless($pipeline && $stage, 422, 'No pipeline to put the request in.');

        // The customer's usual rep first, then the assignment rules.
        $owner = $this->accountOwner($clientId);
        $rule = null;
        if (! $owner) {
            $probe = new CrmLead(['country' => optional($client)->country, 'estimated_value' => $o['value'] ?? 0]);
            [$owner, $rule] = $this->assignment->pick($probe, $via);
        }

        $deal = DB::transaction(function () use ($via, $clientId, $title, $body, $o, $pipeline, $stage, $owner, $rule, $settings) {
            $deal = CrmDeal::create([
                'deal_number' => $this->deals->nextNumber(),
                'title' => mb_substr($title, 0, 191),
                'crm_pipeline_id' => $pipeline->id,
                'crm_pipeline_stage_id' => $stage->id,
                'status' => 'open',
                'client_id' => $clientId,
                'quotation_id' => $o['quotation_id'] ?? null,
                'owner_id' => $owner,
                'value' => round((float) ($o['value'] ?? 0), 2),
                'position' => (int) CrmDeal::where('crm_pipeline_stage_id', $stage->id)->where('status', 'open')->max('position') + 1,
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
                'description' => $body,
                // The customer follows their own request in the client portal.
                'portal_visible' => in_array($via, ['portal_request', 'quote_request'], true),
            ]);
            $this->deals->log($deal, 'created', null, array_filter(['via' => $via, 'rule' => optional($rule)->name]));
            $this->followUp(['crm_deal_id' => $deal->id, 'client_id' => $clientId, 'owner_id' => $owner], $via, $settings);

            return $deal;
        });

        if ($owner && $settings->capture_notify) {
            app(CrmReminderService::class)->notifyUser($owner, 'New request: ' . $deal->title,
                trim(optional($client)->name . ' — ' . (self::LABELS[$via] ?? $via)),
                ['type' => 'crm_deal', 'id' => $deal->id], (bool) $settings->reminder_email, (bool) $settings->reminder_push);
        }

        return $deal;
    }

    /** Public web form submission (already validated against the form's fields). */
    public function fromWebForm(CrmWebForm $form, array $values): array
    {
        $result = $this->capture('web_form', $values, [
            'form' => $form,
            'source_id' => $form->crm_lead_source_id ?: null,
            'status_id' => $form->crm_lead_status_id ?: null,
            'owner_id' => $form->owner_id ?: null,
            'tag_ids' => $form->tag_ids ?: [],
        ]);
        CrmWebForm::whereKey($form->id)->update(['submissions' => DB::raw('submissions + 1'), 'last_submission_at' => now()]);

        return $result;
    }

    // ------------------------------------------------------------------

    private function clean(array $d): array
    {
        $s = fn ($k, $max = 191) => ($v = trim((string) ($d[$k] ?? ''))) === '' ? null : mb_substr(strip_tags($v), 0, $max);
        $first = $s('first_name', 100);
        $last = $s('last_name', 100);
        if (! $first && ! $last && ($full = $s('name'))) {
            $parts = preg_split('/\s+/', $full, 2);
            $first = mb_substr($parts[0], 0, 100);
            $last = isset($parts[1]) ? mb_substr($parts[1], 0, 100) : null;
        }
        $email = $s('email');
        $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
        $company = $s('company_name');

        return [
            'type' => $company && ! $first && ! $last ? 'company' : 'person',
            'first_name' => $first,
            'last_name' => $last,
            'company_name' => $company,
            'job_title' => $s('job_title', 120),
            'email' => $email,
            'phone' => $s('phone', 50),
            'website' => $s('website'),
            'city' => $s('city', 120),
            'country' => $s('country', 120),
            'estimated_value' => is_numeric($d['estimated_value'] ?? null) ? max(0, round((float) $d['estimated_value'], 2)) : 0,
            'subject' => $s('subject'),
            'message' => isset($d['message']) ? mb_substr(trim(strip_tags((string) $d['message'])), 0, 5000) : null,
            'extra' => array_filter((array) ($d['extra'] ?? []), fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** ['subject' => one line, 'body' => the full enquiry]. */
    private function noteText(string $via, array $d): array
    {
        $label = self::LABELS[$via] ?? $via;
        $subject = $d['subject'] ?: ($d['message'] ? mb_strimwidth(preg_replace('/\s+/', ' ', $d['message']), 0, 70, '…') : $label);
        $lines = [];
        if ($d['subject']) {
            $lines[] = $d['subject'];
        }
        if ($d['message']) {
            $lines[] = $d['message'];
        }
        foreach ($d['extra'] as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }

        return ['subject' => $label . ': ' . $subject, 'body' => trim(implode("\n\n", $lines)) ?: null];
    }

    private function openLeadFor(?string $email, ?string $phone): ?CrmLead
    {
        $digits = CrmLead::normalizePhone($phone);
        if (! $email && ! $digits) {
            return null;
        }

        return CrmLead::whereNull('deleted_at')->whereNull('client_id')
            ->where(function ($q) use ($email, $digits) {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($digits) {
                    $q->orWhere('phone_normalized', $digits);
                }
            })->orderByDesc('updated_at')->first();
    }

    private function clientFor(?string $email, ?string $phone): ?int
    {
        $digits = CrmLead::normalizePhone($phone);
        if (! $email && ! $digits) {
            return null;
        }

        return Client::whereNull('deleted_at')->where(function ($q) use ($email, $digits) {
            if ($email) {
                $q->orWhere('email', $email);
            }
            if ($digits) {
                $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '/', '') LIKE ?", ['%' . $digits]);
            }
        })->value('id');
    }

    /** The rep who handles this customer: owner of their latest deal, else of the lead they came from. */
    private function accountOwner(int $clientId): ?int
    {
        $id = CrmDeal::whereNull('deleted_at')->where('client_id', $clientId)->whereNotNull('owner_id')->orderByDesc('updated_at')->value('owner_id')
            ?? CrmLead::whereNull('deleted_at')->where('client_id', $clientId)->whereNotNull('owner_id')->orderByDesc('converted_at')->value('owner_id');

        return $this->activeUser($id);
    }

    private function activeUser($id): ?int
    {
        return $id ? User::whereKey((int) $id)->whereNull('deleted_at')->where('statut', 1)->value('id') : null;
    }

    private function openStatus($id): ?int
    {
        if ($id && CrmLeadStatus::whereKey((int) $id)->where('kind', 'open')->exists()) {
            return (int) $id;
        }

        return CrmLeadStatus::defaultId();
    }

    private function sourceFor(string $via): ?int
    {
        if (! isset(self::SOURCE_NAMES[$via])) {
            return null;
        }
        $settings = CrmSetting::current();
        $map = (array) $settings->capture_sources;
        if (! empty($map[$via]) && CrmLeadSource::whereKey((int) $map[$via])->exists()) {
            return (int) $map[$via];
        }
        $name = self::SOURCE_NAMES[$via];
        $source = CrmLeadSource::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? CrmLeadSource::create(['name' => $name, 'color' => '#0ea5e9', 'is_active' => true, 'sort_order' => (int) CrmLeadSource::max('sort_order') + 1]);
        $map[$via] = $source->id;
        $settings->update(['capture_sources' => $map]);

        return $source->id;
    }

    private function addNote(array $links, array $note): void
    {
        CrmActivity::create(array_merge($links, [
            'type' => 'note',
            'subject' => mb_substr($note['subject'], 0, 191),
            'description' => $note['body'],
            'status' => 'done',
            'done_at' => now(),
            'priority' => 'normal',
        ]));
    }

    /** "Follow up" task due a few hours after the enquiry (crm_settings.capture_followup_hours; empty = none). */
    private function followUp(array $links, string $via, CrmSetting $settings): void
    {
        $hours = $settings->capture_followup_hours;
        if ($hours === null || $hours === '') {
            return;
        }
        CrmActivity::create(array_merge($links, [
            'type' => $via === 'whatsapp' ? 'call' : 'task',
            'subject' => 'Follow up: ' . mb_strtolower(self::LABELS[$via] ?? $via),
            'status' => 'open',
            'priority' => 'high',
            'due_at' => now()->addHours(max(0, (int) $hours)),
            'reminder_minutes' => $settings->default_reminder_minutes,
        ]));
    }

    private function notifyOwner(?int $ownerId, CrmLead $lead, string $via, bool $again): void
    {
        $settings = CrmSetting::current();
        if (! $ownerId || ! $settings->capture_notify) {
            return;
        }
        $title = ($again ? 'Lead is back: ' : 'New lead: ') . $lead->name;
        $body = trim((self::LABELS[$via] ?? $via) . ' · ' . $lead->lead_number . ($lead->email ? ' · ' . $lead->email : '') . ($lead->phone ? ' · ' . $lead->phone : ''));
        app(CrmReminderService::class)->notifyUser($ownerId, $title, $body, ['type' => 'crm_lead', 'id' => $lead->id],
            (bool) $settings->reminder_email, (bool) $settings->reminder_push);
    }
}
