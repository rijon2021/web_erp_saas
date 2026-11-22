<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activity rules: who sees which activities, filling in the linked records,
 * keeping leads' / deals' last_activity_at fresh, and the JSON shape.
 */
class CrmActivityService
{
    /**
     * With crm_view_all every activity; otherwise the ones assigned to or
     * created by the user, plus those on leads / deals the user can see.
     */
    public function visible(?User $user, ?Builder $query = null): Builder
    {
        $query = $query ?? CrmActivity::query();
        $query->whereNull('crm_activities.deleted_at');
        if ($user && ! $user->can('viewAll', CrmLead::class)) {
            $uid = $user->id;
            $query->where(function ($q) use ($uid) {
                $q->where('crm_activities.owner_id', $uid)
                    ->orWhere('crm_activities.created_by', $uid)
                    ->orWhereIn('crm_activities.crm_lead_id', CrmLead::query()->whereNull('deleted_at')
                        ->where(fn ($w) => $w->where('owner_id', $uid)->orWhereNull('owner_id'))->select('id'))
                    ->orWhereIn('crm_activities.crm_deal_id', CrmDeal::query()->whereNull('deleted_at')
                        ->where(fn ($w) => $w->where('owner_id', $uid)->orWhereNull('owner_id'))->select('id'));
            });
        }

        return $query;
    }

    /** Edit / complete / delete: the assignee, the creator, or someone who sees everything. */
    public function canManage(?User $user, CrmActivity $a): bool
    {
        return $user && ((int) $a->owner_id === (int) $user->id || (int) $a->created_by === (int) $user->id
            || $user->can('viewAll', CrmLead::class));
    }

    /** A deal brings its lead and client; a converted lead brings its client. */
    public function fillLinks(array $data): array
    {
        if (! empty($data['crm_deal_id']) && ($deal = CrmDeal::find($data['crm_deal_id']))) {
            $data['crm_lead_id'] = $data['crm_lead_id'] ?? $deal->crm_lead_id;
            $data['client_id'] = $data['client_id'] ?? $deal->client_id;
        }
        if (! empty($data['crm_lead_id']) && empty($data['client_id']) && ($lead = CrmLead::find($data['crm_lead_id']))) {
            $data['client_id'] = $lead->client_id;
        }

        return $data;
    }

    /** A done activity is "activity" for staleness / rotting. */
    public function touchRecords(CrmActivity $a): void
    {
        if ($a->status !== 'done') {
            return;
        }
        $at = $a->done_at ?? Carbon::now();
        if ($a->crm_lead_id) {
            CrmLead::whereKey($a->crm_lead_id)->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))
                ->update(['last_activity_at' => $at]);
        }
        if ($a->crm_deal_id) {
            CrmDeal::whereKey($a->crm_deal_id)->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))
                ->update(['last_activity_at' => $at]);
        }
    }

    /** Activities shown on a lead: its own plus those of its deals. */
    public function forLead(CrmLead $lead)
    {
        $dealIds = CrmDeal::whereNull('deleted_at')->where('crm_lead_id', $lead->id)->pluck('id');

        return CrmActivity::with('owner', 'deal')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('crm_lead_id', $lead->id)->orWhereIn('crm_deal_id', $dealIds))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(due_at, done_at, created_at) DESC')->limit(300)->get();
    }

    public function forDeal(CrmDeal $deal)
    {
        return CrmActivity::with('owner', 'deal')->whereNull('deleted_at')->where('crm_deal_id', $deal->id)
            ->orderByRaw('COALESCE(due_at, done_at, created_at) DESC')->limit(300)->get();
    }

    public function present(CrmActivity $a, ?User $viewer = null): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'subject' => $a->subject,
            'description' => $a->description,
            'status' => $a->status,
            'priority' => $a->priority,
            'due_at' => optional($a->due_at)->toIso8601String(),
            'done_at' => optional($a->done_at)->toIso8601String(),
            'duration_minutes' => $a->duration_minutes,
            'location' => $a->location,
            'outcome' => $a->outcome,
            'reminder_minutes' => $a->reminder_minutes,
            'is_overdue' => $a->isOverdue(),
            'crm_lead_id' => $a->crm_lead_id,
            'crm_deal_id' => $a->crm_deal_id,
            'client_id' => $a->client_id,
            'lead' => $a->relationLoaded('lead') && $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name] : null,
            'deal' => $a->relationLoaded('deal') && $a->deal ? ['id' => $a->deal->id, 'title' => $a->deal->title] : null,
            'client' => $a->relationLoaded('client') && $a->client ? ['id' => $a->client->id, 'name' => $a->client->name] : null,
            'owner' => $a->relationLoaded('owner') && $a->owner ? ['id' => $a->owner->id, 'name' => CrmLeadService::userName($a->owner)] : null,
            'can_manage' => $viewer ? $this->canManage($viewer, $a) : null,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    /** The "next step" summary used on lists and board cards. */
    public static function nextStep(?CrmActivity $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id' => $a->id,
            'type' => $a->type,
            'subject' => $a->subject,
            'due_at' => optional($a->due_at)->toIso8601String(),
            'is_overdue' => $a->isOverdue(),
        ];
    }
}
