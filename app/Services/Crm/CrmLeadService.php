<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\CrmLead;
use App\Models\CrmLeadEvent;
use App\Models\CrmSetting;
use App\Models\CrmTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lead rules shared by the CRM controllers, the importer and later phases:
 * who may see which leads, numbering, duplicate detection, history, tags.
 */
class CrmLeadService
{
    /**
     * Leads this user may see. With "view all" (crm_view_all) every lead;
     * otherwise their own leads plus the unassigned pool, so anyone can pick
     * up a lead nobody owns yet.
     */
    public function visible(?User $user, ?Builder $query = null): Builder
    {
        $query = $query ?? CrmLead::query();
        $query->whereNull('crm_leads.deleted_at');
        if ($user && ! $user->can('viewAll', CrmLead::class)) {
            $query->where(fn ($q) => $q->where('crm_leads.owner_id', $user->id)->orWhereNull('crm_leads.owner_id'));
        }

        return $query;
    }

    public function canSee(?User $user, CrmLead $lead): bool
    {
        return $user && ($user->can('viewAll', CrmLead::class) || ! $lead->owner_id || (int) $lead->owner_id === (int) $user->id);
    }

    /** Next LD-0001 style number; call inside a transaction (the settings row is locked). */
    public function nextNumber(): string
    {
        if (DB::transactionLevel() > 0) {
            DB::table('crm_settings')->orderBy('id')->limit(1)->lockForUpdate()->get(['id']);
        }
        $prefix = trim((string) CrmSetting::current()->lead_prefix) ?: 'LD';

        $last = CrmLead::withTrashed()->where('lead_number', 'LIKE', $prefix . '-%')->pluck('lead_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))->max();

        return $prefix . '-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Leads and clients that look like the same person / company: same email,
     * or same phone (last 9 digits). Converted leads are skipped (their client
     * shows instead).
     */
    public function duplicates(?string $email, ?string $phone, ?int $exceptLeadId = null, int $limit = 5): array
    {
        $email = trim((string) $email);
        $digits = CrmLead::normalizePhone($phone);
        if ($email === '' && ! $digits) {
            return ['leads' => [], 'clients' => []];
        }

        $leads = CrmLead::with('status', 'owner')->whereNull('deleted_at')->whereNull('client_id')
            ->when($exceptLeadId, fn ($q) => $q->where('id', '!=', $exceptLeadId))
            ->where(function ($q) use ($email, $digits) {
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
                if ($digits) {
                    $q->orWhere('phone_normalized', $digits);
                }
            })->limit($limit)->get();

        $clients = Client::query()->whereNull('deleted_at')
            ->where(function ($q) use ($email, $digits) {
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
                if ($digits) {
                    // Clients have no normalised column: compare on the digits' tail.
                    $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '/', '') LIKE ?", ['%' . $digits]);
                }
            })->limit($limit)->get(['id', 'name', 'email', 'phone', 'code']);

        return [
            'leads' => $leads->map(fn ($l) => [
                'id' => $l->id,
                'lead_number' => $l->lead_number,
                'name' => $l->name,
                'email' => $l->email,
                'phone' => $l->phone,
                'status' => optional($l->status)->name,
                'owner' => $l->userName($l->owner),
                'match' => $email !== '' && strcasecmp((string) $l->email, $email) === 0 ? 'email' : 'phone',
            ])->values()->all(),
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'code' => $c->code,
                'match' => $email !== '' && strcasecmp((string) $c->email, $email) === 0 ? 'email' : 'phone',
            ])->values()->all(),
        ];
    }

    public function log(CrmLead $lead, string $type, ?string $note = null, array $meta = []): void
    {
        $user = auth()->user();
        CrmLeadEvent::create([
            'crm_lead_id' => $lead->id,
            'type' => $type,
            'note' => $note,
            'meta' => $meta ?: null,
            'user_id' => $user instanceof User ? $user->id : null,
        ]);
    }

    /**
     * Set a lead's tags from a mixed list: existing tag ids, or new tag names
     * (created on the fly, matched case-insensitively against existing ones).
     */
    public function syncTags(CrmLead $lead, array $values): void
    {
        $lead->tags()->sync($this->tagIds($values));
        CrmScoringService::refresh($lead->id); // tag rules
    }

    public function tagIds(array $values): array
    {
        $ids = [];
        foreach ($values as $v) {
            if (is_numeric($v) && CrmTag::whereKey((int) $v)->exists()) {
                $ids[] = (int) $v;
                continue;
            }
            $name = trim((string) $v);
            if ($name === '' || is_numeric($v)) {
                continue;
            }
            $tag = CrmTag::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? CrmTag::create(['name' => mb_substr($name, 0, 80)]);
            $ids[] = $tag->id;
        }

        return array_values(array_unique($ids));
    }

    public static function userName(?User $u): ?string
    {
        if (! $u) {
            return null;
        }

        return trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: $u->username;
    }
}
