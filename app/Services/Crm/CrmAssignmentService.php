<?php

namespace App\Services\Crm;

use App\Models\CrmAssignmentRule;
use App\Models\CrmLead;
use App\Models\User;
use App\Services\CountryService;
use Illuminate\Support\Facades\DB;

/**
 * Picks an owner for a new lead from the assignment rules: the first active
 * rule whose conditions match wins; it names one rep, or rotates between
 * several (round robin, skipping reps who left). No match = unassigned pool.
 */
class CrmAssignmentService
{
    /**
     * @return array{0: ?int, 1: ?CrmAssignmentRule} [user id, the rule that matched]
     */
    public function pick(CrmLead $lead, string $channel): array
    {
        $rules = CrmAssignmentRule::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($rules as $rule) {
            if (! $this->matches($rule, $lead, $channel)) {
                continue;
            }
            $userId = $this->userFor($rule);
            if ($userId) {
                return [$userId, $rule];
            }
        }

        return [null, null];
    }

    public function matches(CrmAssignmentRule $rule, CrmLead $lead, string $channel): bool
    {
        $c = (array) $rule->conditions;
        if (! empty($c['channels']) && ! in_array($channel, (array) $c['channels'], true)) {
            return false;
        }
        if (! empty($c['source_ids']) && ! in_array((int) $lead->crm_lead_source_id, array_map('intval', (array) $c['source_ids']), true)) {
            return false;
        }
        if (! empty($c['countries'])) {
            $hit = false;
            foreach ((array) $c['countries'] as $country) {
                if ($lead->country && CountryService::sameCountry((string) $country, (string) $lead->country)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }
        if (isset($c['min_value']) && $c['min_value'] !== null && $c['min_value'] !== ''
            && (float) $lead->estimated_value < (float) $c['min_value']) {
            return false;
        }

        return true;
    }

    /** The rule's rep (or next rep in the rotation); the rule row is locked so two leads can't take the same turn. */
    private function userFor(CrmAssignmentRule $rule): ?int
    {
        return DB::transaction(function () use ($rule) {
            $rule = CrmAssignmentRule::whereKey($rule->id)->lockForUpdate()->first();
            $ids = array_values(array_unique(array_map('intval', (array) $rule->user_ids)));
            $active = User::whereIn('id', $ids)->whereNull('deleted_at')->where('statut', 1)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $ids = array_values(array_filter($ids, fn ($id) => in_array($id, $active, true)));
            if (! $ids) {
                return null;
            }

            if ($rule->method === 'round_robin' && count($ids) > 1) {
                $pos = array_search((int) $rule->last_user_id, $ids, true);
                $userId = $ids[$pos === false ? 0 : ($pos + 1) % count($ids)];
            } else {
                $userId = $ids[0];
            }
            $rule->forceFill(['last_user_id' => $userId, 'assigned_count' => $rule->assigned_count + 1])->save();

            return $userId;
        });
    }
}
