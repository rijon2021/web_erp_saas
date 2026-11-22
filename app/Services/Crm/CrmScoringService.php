<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmScoringRule;
use App\Models\CrmSetting;
use App\Services\CountryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead score 0–100: the sum of the matching scoring rules' points, clamped.
 * Refreshed when a lead, its activities, deals or messages change (model
 * hooks call refresh()), and nightly by `crm:daily` for the time-based rules.
 */
class CrmScoringService
{
    private ?\Illuminate\Support\Collection $rules = null;

    private static bool $paused = false;

    /** tenant key => scoring tables migrated? (per tenant: commands loop tenants in one process) */
    private static array $installed = [];

    /** Recompute and store one lead's score (quietly: no updated_at, no events). */
    public static function refresh(?int $leadId): void
    {
        if (! $leadId || self::$paused) {
            return;
        }
        try {
            // Checked once per request: imports save hundreds of leads in a row.
            $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';
            self::$installed[$key] ??= Schema::hasTable('crm_scoring_rules');
            if (! self::$installed[$key] || ! CrmSetting::current()->scoring_enabled) {
                return;
            }
            $lead = CrmLead::whereNull('deleted_at')->find($leadId);
            if ($lead) {
                app(self::class)->store($lead);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Run $fn without per-save refreshes (bulk work refreshes once afterwards). */
    public static function paused(callable $fn)
    {
        $was = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $was;
        }
    }

    public function store(CrmLead $lead): int
    {
        [$score] = $this->compute($lead);
        if ((int) $lead->score !== $score) {
            DB::table('crm_leads')->where('id', $lead->id)->update(['score' => $score]);
            $lead->score = $score;
        }

        return $score;
    }

    /** All open, unconverted leads (nightly). @return int leads whose score changed */
    public function refreshAll(): int
    {
        $changed = 0;
        CrmLead::whereNull('deleted_at')->whereNull('client_id')->orderBy('id')->chunkById(300, function ($leads) use (&$changed) {
            foreach ($leads as $lead) {
                $before = (int) $lead->score;
                if ($this->store($lead) !== $before) {
                    $changed++;
                }
            }
        });

        return $changed;
    }

    /** @return array{0: int, 1: array} [score, breakdown of matched rules] */
    public function compute(CrmLead $lead): array
    {
        $rules = $this->rules();
        if ($rules->isEmpty()) {
            return [0, []];
        }
        $facts = $this->facts($lead, $rules);
        $sum = 0;
        $lines = [];
        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $lead, $facts)) {
                $sum += (int) $rule->points;
                $lines[] = ['id' => $rule->id, 'criterion' => $rule->criterion, 'value' => $rule->value, 'points' => (int) $rule->points];
            }
        }

        return [max(0, min(100, $sum)), $lines];
    }

    private function rules()
    {
        return $this->rules ??= CrmScoringRule::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Only the counts the active rules need. */
    private function facts(CrmLead $lead, $rules): array
    {
        $need = $rules->pluck('criterion')->unique()->flip();
        $f = [];
        if (isset($need['done_activities_at_least'])) {
            $f['done'] = DB::table('crm_activities')->whereNull('deleted_at')->where('crm_lead_id', $lead->id)->where('status', 'done')->count();
        }
        if (isset($need['has_open_deal'])) {
            $f['open_deal'] = DB::table('crm_deals')->whereNull('deleted_at')->where('crm_lead_id', $lead->id)->where('status', 'open')->exists();
        }
        if (isset($need['has_reply'])) {
            $f['reply'] = Schema::hasTable('crm_messages')
                && DB::table('crm_messages')->where('crm_lead_id', $lead->id)->where('direction', 'in')->exists();
        }
        if (isset($need['tag'])) {
            $f['tags'] = DB::table('crm_taggables')->where('taggable_type', 'lead')->where('taggable_id', $lead->id)
                ->pluck('crm_tag_id')->map(fn ($v) => (int) $v)->all();
        }

        return $f;
    }

    private function ruleMatches(CrmScoringRule $r, CrmLead $l, array $f): bool
    {
        $v = (string) $r->value;
        $now = Carbon::now();

        switch ($r->criterion) {
            case 'has_email': return (bool) $l->email;
            case 'has_phone': return (bool) $l->phone;
            case 'has_company': return trim((string) $l->company_name) !== '';
            case 'is_company': return $l->type === 'company';
            case 'rating': return $l->rating === $v;
            case 'source': return (int) $l->crm_lead_source_id === (int) $v;
            case 'captured_via': return $l->captured_via === $v || ($v === 'manual' && ! $l->captured_via);
            case 'value_at_least': return (float) $l->estimated_value >= (float) $v;
            case 'country': return $l->country && CountryService::sameCountry($v, (string) $l->country);
            case 'tag': return in_array((int) $v, $f['tags'] ?? [], true);
            case 'recent_activity': return $l->last_activity_at && $l->last_activity_at->gte($now->copy()->subDays(max(1, (int) $v)));
            case 'no_activity': return ! $l->last_activity_at || $l->last_activity_at->lt($now->copy()->subDays(max(1, (int) $v)));
            case 'done_activities_at_least': return ($f['done'] ?? 0) >= max(1, (int) $v);
            case 'has_open_deal': return (bool) ($f['open_deal'] ?? false);
            case 'has_reply': return (bool) ($f['reply'] ?? false);
        }

        return false;
    }
}
