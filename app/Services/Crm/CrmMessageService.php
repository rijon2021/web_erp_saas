<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsappLog;
use App\Services\WhatsApp\WhatsAppGateway as WhatsAppService;
use Carbon\Carbon;

/**
 * The CRM conversation: placeholders, sending email / SMS / WhatsApp,
 * matching incoming WhatsApp messages to a lead or customer, and the thread
 * shown on lead / deal / customer pages.
 */
class CrmMessageService
{
    public const PLACEHOLDERS = [
        'customer_name', 'first_name', 'company', 'email', 'phone', 'lead_number',
        'deal_title', 'deal_number', 'deal_value', 'owner_name', 'my_name', 'company_name',
    ];

    public function __construct(private CrmMessageTransport $transport)
    {
    }

    public function availability(): array
    {
        return $this->transport->availability();
    }

    /** Values for the {placeholders} of a lead / deal / customer. */
    public function variables(?CrmLead $lead, ?CrmDeal $deal, ?Client $client, ?User $sender): array
    {
        $lead = $lead ?? ($deal ? $deal->lead : null);
        $client = $client ?? ($deal ? $deal->client : ($lead ? $lead->client : null));
        $owner = $deal ? $deal->owner : ($lead ? $lead->owner : null);
        $settings = Setting::whereNull('deleted_at')->first();
        $symbol = optional(\App\Models\Currency::find(optional($settings)->currency_id))->symbol ?? '';

        return [
            'customer_name' => $client ? $client->name : optional($lead)->name,
            'first_name' => $lead && $lead->first_name ? $lead->first_name : ($client ? ($client->firstname ?: explode(' ', (string) $client->name)[0]) : ''),
            'company' => $lead ? (string) $lead->company_name : '',
            'email' => $client && $client->email ? $client->email : optional($lead)->email,
            'phone' => $client && $client->phone ? $client->phone : optional($lead)->phone,
            'lead_number' => optional($lead)->lead_number,
            'deal_title' => optional($deal)->title,
            'deal_number' => optional($deal)->deal_number,
            'deal_value' => $deal ? trim($symbol . ' ' . number_format((float) $deal->value, 2)) : '',
            'owner_name' => CrmLeadService::userName($owner),
            'my_name' => CrmLeadService::userName($sender),
            'company_name' => optional($settings)->CompanyName,
        ];
    }

    public function render(?string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }

        return strtr((string) $text, $map);
    }

    /**
     * Send one message and record it (also when it fails, so the thread
     * shows what happened). Returns the CrmMessage.
     */
    public function send(array $d, User $sender): CrmMessage
    {
        $lead = ! empty($d['crm_lead_id']) ? CrmLead::find($d['crm_lead_id']) : null;
        $deal = ! empty($d['crm_deal_id']) ? CrmDeal::with('lead', 'client', 'owner')->find($d['crm_deal_id']) : null;
        $client = ! empty($d['client_id']) ? Client::find($d['client_id']) : null;
        $vars = $this->variables($lead, $deal, $client, $sender);
        $subject = $d['channel'] === 'email' ? mb_substr($this->render($d['subject'] ?? '', $vars), 0, 191) : null;
        $body = $this->render($d['body'], $vars);

        $msg = CrmMessage::create([
            'channel' => $d['channel'],
            'direction' => 'out',
            'crm_lead_id' => $lead ? $lead->id : ($deal ? $deal->crm_lead_id : null),
            'crm_deal_id' => optional($deal)->id,
            'client_id' => $client ? $client->id : ($deal ? $deal->client_id : optional($lead)->client_id),
            'to' => $d['to'],
            'subject' => $subject,
            'body' => $body,
            'status' => 'sent',
            'marketing_template_id' => $d['marketing_template_id'] ?? null,
            'user_id' => $sender->id,
        ]);

        try {
            switch ($d['channel']) {
                case 'email':
                    $this->transport->email($d['to'], $subject ?: '(no subject)', $body);
                    break;
                case 'sms':
                    $this->transport->sms($d['to'], $body);
                    break;
                case 'whatsapp':
                    if (! WhatsAppService::isActive()) {
                        throw new \RuntimeException('WhatsApp is not connected (Integrations → WhatsApp).');
                    }
                    $phone = WhatsAppService::normalizePhone($d['to']);
                    if (! $phone) {
                        throw new \RuntimeException('This phone number cannot receive WhatsApp messages.');
                    }
                    $ref = $deal ? ['crm_deal', $deal->id] : ($lead ? ['crm_lead', $lead->id] : ['client', optional($client)->id]);
                    $log = WhatsAppService::sendNow($phone, $body, [
                        'event' => 'crm', 'recipient_name' => $vars['customer_name'],
                        'reference_type' => $ref[0], 'reference_id' => $ref[1],
                    ]);
                    $msg->whatsapp_message_id = $log->id;
                    if ($log->status === WhatsappLog::STATUS_FAILED) {
                        throw new \RuntimeException($log->error ?: 'WhatsApp send failed');
                    }
                    break;
                case 'portal':
                    // Shown to the customer on their request in the client portal.
                    $portal = $deal ? self::portalAccount($deal->client_id) : null;
                    if (! $portal) {
                        throw new \RuntimeException('This customer has no active client portal account.');
                    }
                    if (! $deal->portal_visible) {
                        CrmDeal::whereKey($deal->id)->update(['portal_visible' => true]);
                    }
                    $this->emailPortalCustomer($portal->email, $deal, $sender, $body);
                    break;
            }
            $msg->save();
            $this->touch($msg);
        } catch (\Throwable $e) {
            $msg->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $msg;
    }

    /**
     * Called by WhatsAppWebhookController when an inbound message is stored
     * (a whatsapp_logs row, message_type 'inbound', sender in `recipient`):
     * copy it to the CRM when the number belongs to a lead, a customer or a
     * contact.
     */
    public function matchInbound(WhatsappLog $wa): ?CrmMessage
    {
        $wa->phone = $wa->recipient;
        $wa->recipient_name = $wa->meta['name'] ?? null;
        $digits = CrmLead::normalizePhone($wa->phone);
        if (! $digits || CrmMessage::where('whatsapp_message_id', $wa->id)->exists()) {
            return null;
        }

        $lead = CrmLead::whereNull('deleted_at')->where('phone_normalized', $digits)->orderByDesc('updated_at')->first();
        $clientId = $lead ? $lead->client_id : null;
        if (! $clientId) {
            $clientId = Client::whereNull('deleted_at')
                ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '/', '') LIKE ?", ['%' . $digits])
                ->value('id')
                ?? CrmContact::whereNull('deleted_at')
                    ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '/', '') LIKE ?", ['%' . $digits])
                    ->value('client_id');
        }
        if (! $lead && ! $clientId) {
            // Optionally, a message from an unknown number starts a new lead (CRM Settings → Lead capture).
            $created = null;
            CrmCaptureService::hook('capture_whatsapp_unknown', function ($crm) use ($wa, &$created) {
                $created = $crm->capture('whatsapp', [
                    'name' => $wa->recipient_name, 'phone' => '+' . ltrim((string) $wa->phone, '+'),
                ], ['note' => false]);
            });
            $lead = ! empty($created['lead_id']) ? CrmLead::find($created['lead_id']) : null;
            if (! $lead) {
                return null;
            }
        }

        $deal = CrmDeal::whereNull('deleted_at')->where('status', 'open')
            ->where(fn ($q) => $q->when($lead, fn ($w) => $w->orWhere('crm_lead_id', $lead->id))->when($clientId, fn ($w) => $w->orWhere('client_id', $clientId)))
            ->orderByDesc('updated_at')->first();

        $msg = CrmMessage::create([
            'channel' => 'whatsapp',
            'direction' => 'in',
            'crm_lead_id' => optional($lead)->id,
            'crm_deal_id' => optional($deal)->id,
            'client_id' => $clientId,
            'to' => $wa->phone,
            'body' => (string) $wa->body,
            'status' => 'received',
            'whatsapp_message_id' => $wa->id,
            'owner_id' => optional($deal)->owner_id ?? optional($lead)->owner_id,
        ]);
        $this->touch($msg);

        return $msg;
    }

    /** Messages of a lead (its own + its customer's), a deal or a customer, oldest first. */
    public function thread(?CrmLead $lead, ?CrmDeal $deal, ?Client $client)
    {
        $q = CrmMessage::with('user', 'whatsapp');
        if ($deal) {
            $q->where(fn ($w) => $w->where('crm_deal_id', $deal->id)
                ->when($deal->crm_lead_id, fn ($x) => $x->orWhere('crm_lead_id', $deal->crm_lead_id))
                ->when($deal->client_id, fn ($x) => $x->orWhere('client_id', $deal->client_id)));
        } elseif ($lead) {
            $q->where(fn ($w) => $w->where('crm_lead_id', $lead->id)
                ->when($lead->client_id, fn ($x) => $x->orWhere('client_id', $lead->client_id)));
        } elseif ($client) {
            $q->where('client_id', $client->id);
        } else {
            return collect();
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()->reverse()->values();
    }

    public function present(CrmMessage $m): array
    {
        // A WhatsApp row shows Meta's latest delivery state.
        $status = $m->status;
        if ($m->channel === 'whatsapp' && $m->direction === 'out' && $m->whatsapp) {
            $status = $m->whatsapp->status;
        }
        if ($m->channel === 'portal' && $m->direction === 'out' && $m->status === 'sent' && $m->portal_read_at) {
            $status = 'read'; // the customer opened it in the portal
        }

        return [
            'id' => $m->id,
            'channel' => $m->channel,
            'direction' => $m->direction,
            'to' => $m->to,
            'subject' => $m->subject,
            'body' => $m->body,
            'status' => $status,
            'error' => $m->error ?: ($m->whatsapp && $m->whatsapp->status === 'failed' ? $m->whatsapp->error : null),
            'unread' => $m->direction === 'in' && ! $m->read_at,
            'user_name' => CrmLeadService::userName($m->user),
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    /** The customer's active portal login (null when they have none). */
    public static function portalAccount(?int $clientId): ?object
    {
        if (! $clientId || ! \Illuminate\Support\Facades\Schema::hasTable('portal_clients')) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('portal_clients')->where('client_id', $clientId)->where('status', 1)->first(['id', 'email', 'preferred_locale']);
    }

    /** "You have a new message" email with a link to the request in the portal (best effort). */
    private function emailPortalCustomer(?string $email, CrmDeal $deal, User $sender, string $body): void
    {
        if (! $email || ! \Illuminate\Support\Facades\DB::table('servers')->whereNull('deleted_at')->exists()) {
            return;
        }
        try {
            // SaaS portal: /portal/requests belongs to Protocol & Requests, so CRM deals live under /portal/deals.
            $link = CrmLeadSegmentResolver::tenantBaseUrl() . '/portal/deals/' . $deal->id;
            $this->transport->email($email, 'New message about: ' . $deal->title,
                CrmLeadService::userName($sender) . ":\n\n" . mb_strimwidth($body, 0, 600, '…') . "\n\nReply in your client portal: " . $link);
        } catch (\Throwable $e) {
            report($e); // the message itself is saved; the email is only a nudge
        }
    }

    private function touch(CrmMessage $m): void
    {
        $at = $m->created_at ?? Carbon::now();
        if ($m->crm_lead_id) {
            CrmLead::whereKey($m->crm_lead_id)->update(['last_activity_at' => $at]);
        }
        if ($m->crm_deal_id) {
            CrmDeal::whereKey($m->crm_deal_id)->update(['last_activity_at' => $at]);
        }
    }
}
