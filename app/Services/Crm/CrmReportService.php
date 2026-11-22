<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLostReason;
use App\Models\CrmPipeline;
use App\Models\CrmTarget;
use App\Models\CrmWebForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRM reporting: the numbers behind the Reports page, targets, the dashboard
 * and the weekly summary. Every method takes an owner scope: null = everyone,
 * or a list of user ids (a rep without "view all" only ever sees their own).
 */
class CrmReportService
{
    /** Activity types that count as "work done" (notes don't). */
    public const WORK_TYPES = ['call', 'meeting', 'email', 'task'];

    /**
     * Owner scope for a user: view-all users may pick a rep ($owner id) or
     * everyone (null); everyone else is limited to themselves.
     */
    public function scopeFor(User $user, $owner = null): ?array
    {
        if (! $user->can('viewAll', CrmLead::class)) {
            return [(int) $user->id];
        }

        return ctype_digit((string) $owner) ? [(int) $owner] : null;
    }

    // ------------------------------------------------------------------
    // Targets

    /** Live actual for a target metric over [from, to]. $userId null = team. */
    public function actual(string $metric, ?int $userId, Carbon $from, Carbon $to): float
    {
        $own = fn ($q, $col = 'owner_id') => $userId ? $q->where($col, $userId) : $q;

        return (float) match ($metric) {
            'won_value' => $own($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]))->sum('value'),
            'won_deals' => $own($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]))->count(),
            'new_leads' => $own($this->leads()->whereBetween('created_at', [$from, $to]))->count(),
            'converted_leads' => $own($this->leads()->whereBetween('converted_at', [$from, $to]))->count(),
            'activities_done' => $own($this->acts()->where('status', 'done')->whereIn('type', self::WORK_TYPES)->whereBetween('done_at', [$from, $to]))->count(),
            default => 0,
        };
    }

    /**
     * Targets of a period with their actuals.
     * @return Collection of [user_id, metric, target, actual, percent]
     */
    public function targetProgress(string $type, Carbon $start, ?array $userIds = null, bool $withTeam = true): Collection
    {
        [$from, $to] = CrmTarget::bounds($type, $start);
        $now = Carbon::now();

        return CrmTarget::where('period_type', $type)->whereDate('period_start', $from->toDateString())
            ->where(function ($q) use ($userIds, $withTeam) {
                if ($userIds === null) {
                    return;
                }
                $q->whereIn('user_id', $userIds);
                if ($withTeam) {
                    $q->orWhereNull('user_id');
                }
            })
            ->get()
            ->map(function (CrmTarget $t) use ($from, $to, $now) {
                $actual = $this->actual($t->metric, $t->user_id, $from, $to);
                $target = (float) $t->target_value;
                // Where the rep "should" be by now if the goal were spread evenly over the period.
                $elapsed = $now->lt($from) ? 0 : ($now->gt($to) ? 1 : $from->diffInSeconds($now) / max(1, $from->diffInSeconds($to)));

                return [
                    'id' => $t->id,
                    'user_id' => $t->user_id,
                    'metric' => $t->metric,
                    'money' => CrmTarget::METRICS[$t->metric] ?? false,
                    'target' => $target,
                    'actual' => round($actual, 2),
                    'percent' => $target > 0 ? round($actual / $target * 100, 1) : null,
                    'expected_percent' => round($elapsed * 100, 1),
                ];
            })->values();
    }

    // ------------------------------------------------------------------
    // Report sections

    public function overview(?array $scope, Carbon $from, Carbon $to, ?int $pipelineId = null): array
    {
        $won = $this->own($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]), $scope);
        $lost = $this->own($this->deals()->where('status', 'lost')->whereBetween('lost_at', [$from, $to]), $scope);
        $wonCount = (clone $won)->count();
        $lostCount = (clone $lost)->count();
        $wonValue = (float) (clone $won)->sum('value');
        $cycle = (clone $won)->get(['created_at', 'won_at'])->map(fn ($d) => $d->created_at && $d->won_at ? $d->created_at->diffInDays($d->won_at) : null)->filter(fn ($v) => $v !== null);

        $leadsConverted = $this->own($this->leads()->whereBetween('converted_at', [$from, $to]), $scope)->count();
        $leadsLost = $this->own($this->leads()->whereBetween('lost_at', [$from, $to]), $scope)->count();
        $open = $this->own($this->deals()->where('status', 'open'), $scope)->with('stage')->get(['id', 'value', 'crm_pipeline_stage_id']);

        return [
            'kpis' => [
                'new_leads' => $this->own($this->leads()->whereBetween('created_at', [$from, $to]), $scope)->count(),
                'converted_leads' => $leadsConverted,
                'lead_conversion' => ($leadsConverted + $leadsLost) ? round($leadsConverted / ($leadsConverted + $leadsLost) * 100, 1) : null,
                'new_deals' => $this->own($this->deals()->whereBetween('created_at', [$from, $to]), $scope)->count(),
                'won_deals' => $wonCount,
                'won_value' => round($wonValue, 2),
                'lost_deals' => $lostCount,
                'lost_value' => round((float) (clone $lost)->sum('value'), 2),
                'win_rate' => ($wonCount + $lostCount) ? round($wonCount / ($wonCount + $lostCount) * 100, 1) : null,
                'avg_deal' => $wonCount ? round($wonValue / $wonCount, 2) : null,
                'avg_cycle_days' => $cycle->count() ? round($cycle->avg(), 1) : null,
                'activities_done' => $this->own($this->acts()->where('status', 'done')->whereIn('type', self::WORK_TYPES)->whereBetween('done_at', [$from, $to]), $scope)->count(),
                'open_deals' => $open->count(),
                'open_value' => round((float) $open->sum('value'), 2),
                'weighted' => round($open->sum(fn ($d) => $d->weightedValue()), 2),
            ],
            'trend' => $this->trend($scope, $from, $to),
            'funnel' => $this->funnel($scope, $from, $to, $pipelineId),
        ];
    }

    /** New leads / won value / won deals per day (≤ 62 days) or per month. */
    public function trend(?array $scope, Carbon $from, Carbon $to): array
    {
        $daily = $from->diffInDays($to) <= 62;
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';
        $bucket = fn ($col) => DB::raw("DATE_FORMAT($col, '$fmt') as b");

        $leads = $this->own($this->leads()->whereBetween('created_at', [$from, $to]), $scope)
            ->select($bucket('created_at'), DB::raw('COUNT(*) as n'))->groupBy('b')->pluck('n', 'b');
        $won = $this->own($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]), $scope)
            ->select($bucket('won_at'), DB::raw('COUNT(*) as n'), DB::raw('SUM(value) as v'))->groupBy('b')->get()->keyBy('b');
        $lost = $this->own($this->deals()->where('status', 'lost')->whereBetween('lost_at', [$from, $to]), $scope)
            ->select($bucket('lost_at'), DB::raw('COUNT(*) as n'), DB::raw('SUM(value) as v'))->groupBy('b')->get()->keyBy('b');

        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $daily ? $d->addDay() : $d->addMonthNoOverflow()->startOfMonth()) {
            $k = $daily ? $d->toDateString() : $d->format('Y-m');
            $out[] = [
                'd' => $k,
                'new_leads' => (int) ($leads[$k] ?? 0),
                'won_deals' => (int) optional($won->get($k))->n,
                'won_value' => round((float) optional($won->get($k))->v, 2),
                'lost_deals' => (int) optional($lost->get($k))->n,
                'lost_value' => round((float) optional($lost->get($k))->v, 2),
            ];
        }

        return $out;
    }

    /**
     * Stage funnel of the deals created in the period: how many reached at
     * least each stage (a deal counts up to its current stage; won deals
     * reached every stage), then won.
     */
    public function funnel(?array $scope, Carbon $from, Carbon $to, ?int $pipelineId = null): array
    {
        $pipeline = $pipelineId ? CrmPipeline::find($pipelineId) : CrmPipeline::defaultPipeline();
        if (! $pipeline) {
            return ['pipeline' => null, 'stages' => []];
        }
        $stages = $pipeline->stages()->get(['id', 'name', 'color', 'sort_order', 'probability']);
        $order = $stages->values()->mapWithKeys(fn ($s, $i) => [$s->id => $i]);
        $deals = $this->own($this->deals()->where('crm_pipeline_id', $pipeline->id)->whereBetween('created_at', [$from, $to]), $scope)
            ->get(['id', 'status', 'value', 'crm_pipeline_stage_id']);

        $rows = [];
        foreach ($stages->values() as $i => $s) {
            $reached = $deals->filter(fn ($d) => $d->status === 'won' || ($order[$d->crm_pipeline_stage_id] ?? -1) >= $i);
            $rows[] = ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'count' => $reached->count(), 'value' => round((float) $reached->sum('value'), 2)];
        }
        $wonDeals = $deals->where('status', 'won');
        $rows[] = ['id' => 'won', 'name' => null, 'color' => '#16a34a', 'count' => $wonDeals->count(), 'value' => round((float) $wonDeals->sum('value'), 2)];
        foreach ($rows as $i => &$r) {
            $r['from_previous'] = $i > 0 && $rows[$i - 1]['count'] ? round($r['count'] / $rows[$i - 1]['count'] * 100, 1) : null;
            $r['from_start'] = $rows[0]['count'] ? round($r['count'] / $rows[0]['count'] * 100, 1) : null;
        }

        return ['pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name], 'total' => $deals->count(), 'stages' => $rows];
    }

    public function winLoss(?array $scope, Carbon $from, Carbon $to): array
    {
        $reasons = CrmLostReason::withTrashed()->get()->keyBy('id');
        $lostDeals = $this->own($this->deals()->where('status', 'lost')->whereBetween('lost_at', [$from, $to]), $scope);
        $byReason = (clone $lostDeals)->select('crm_lost_reason_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(value) as v'))
            ->groupBy('crm_lost_reason_id')->get()
            ->map(fn ($r) => ['id' => $r->crm_lost_reason_id, 'name' => optional($reasons->get($r->crm_lost_reason_id))->name, 'count' => (int) $r->n, 'value' => round((float) $r->v, 2)])
            ->sortByDesc('count')->values();
        $leadReasons = $this->own($this->leads()->whereBetween('lost_at', [$from, $to]), $scope)
            ->select('crm_lost_reason_id', DB::raw('COUNT(*) as n'))->groupBy('crm_lost_reason_id')->get()
            ->map(fn ($r) => ['id' => $r->crm_lost_reason_id, 'name' => optional($reasons->get($r->crm_lost_reason_id))->name, 'count' => (int) $r->n])
            ->sortByDesc('count')->values();
        $byStage = (clone $lostDeals)->with('stage')->get()->groupBy('crm_pipeline_stage_id')
            ->map(fn ($g) => ['name' => optional($g->first()->stage)->name, 'color' => optional($g->first()->stage)->color, 'count' => $g->count(), 'value' => round((float) $g->sum('value'), 2)])
            ->sortByDesc('count')->values();

        $top = fn ($status, $col) => $this->own($this->deals()->with('owner', 'client', 'lead')->where('status', $status)->whereBetween($col, [$from, $to]), $scope)
            ->orderByDesc('value')->limit(8)->get()->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title, 'deal_number' => $d->deal_number, 'value' => (float) $d->value,
                'owner' => CrmLeadService::userName($d->owner), 'who' => optional($d->client)->name ?? optional($d->lead)->name,
                'date' => optional($d->{$col})->toIso8601String(), 'reason' => optional($reasons->get($d->crm_lost_reason_id))->name,
            ])->values();

        return [
            'trend' => $this->trend($scope, $from, $to),
            'deal_reasons' => $byReason,
            'lead_reasons' => $leadReasons,
            'lost_by_stage' => $byStage,
            'top_won' => $top('won', 'won_at'),
            'top_lost' => $top('lost', 'lost_at'),
        ];
    }

    /** Open deals by expected close month (overdue, this month … +5, no date), plus per rep. */
    public function forecast(?array $scope): array
    {
        $today = Carbon::today();
        $open = $this->own($this->deals()->where('status', 'open'), $scope)->with('stage', 'owner', 'client', 'lead')->get();
        $buckets = [['key' => 'overdue', 'label' => null, 'deals' => collect()]];
        for ($i = 0; $i < 6; $i++) {
            $m = $today->copy()->startOfMonth()->addMonthsNoOverflow($i);
            $buckets[] = ['key' => $m->format('Y-m'), 'label' => $m->format('Y-m'), 'deals' => collect()];
        }
        $buckets[] = ['key' => 'later', 'label' => null, 'deals' => collect()];
        $buckets[] = ['key' => 'none', 'label' => null, 'deals' => collect()];
        $index = collect($buckets)->pluck('key')->flip();

        foreach ($open as $d) {
            $date = $d->expected_close_date;
            $key = ! $date ? 'none' : ($date->lt($today) ? 'overdue' : ($index->has($date->format('Y-m')) ? $date->format('Y-m') : 'later'));
            $buckets[$index[$key]]['deals']->push($d);
        }

        $months = collect($buckets)->map(fn ($b) => [
            'key' => $b['key'],
            'count' => $b['deals']->count(),
            'value' => round((float) $b['deals']->sum('value'), 2),
            'weighted' => round($b['deals']->sum(fn ($d) => $d->weightedValue()), 2),
        ])->values();

        $owners = $open->groupBy('owner_id')->map(fn ($g, $ownerId) => [
            'id' => $ownerId ?: null,
            'name' => $ownerId ? CrmLeadService::userName($g->first()->owner) : null,
            'count' => $g->count(),
            'value' => round((float) $g->sum('value'), 2),
            'weighted' => round($g->sum(fn ($d) => $d->weightedValue()), 2),
            'this_month' => round((float) $g->filter(fn ($d) => $d->expected_close_date && $d->expected_close_date->isSameMonth($today) && $d->expected_close_date->gte($today))->sum('value'), 2),
        ])->sortByDesc('weighted')->values();

        $closing = $open->filter(fn ($d) => $d->expected_close_date && $d->expected_close_date->isSameMonth($today))
            ->sortByDesc('value')->take(10)->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title, 'value' => (float) $d->value, 'weighted' => $d->weightedValue(),
                'probability' => (int) optional($d->stage)->probability, 'stage' => optional($d->stage)->name, 'stage_color' => optional($d->stage)->color,
                'owner' => CrmLeadService::userName($d->owner), 'who' => optional($d->client)->name ?? optional($d->lead)->name,
                'expected_close_date' => optional($d->expected_close_date)->toDateString(), 'overdue' => $d->isOverdue(),
            ])->values();

        [$mFrom, $mTo] = CrmTarget::bounds('month');
        $wonThisMonth = $this->own($this->deals()->where('status', 'won')->whereBetween('won_at', [$mFrom, $mTo]), $scope)->sum('value');
        $closingWeighted = $open->filter(fn ($d) => $d->expected_close_date && $d->expected_close_date->isSameMonth($today) && $d->expected_close_date->gte($today))
            ->sum(fn ($d) => $d->weightedValue());

        return [
            'months' => $months,
            'owners' => $owners,
            'closing' => $closing,
            'this_month' => [
                'won' => round((float) $wonThisMonth, 2),
                'projected' => round((float) $wonThisMonth + $closingWeighted, 2),
                'target' => $scope === null
                    ? (float) CrmTarget::whereNull('user_id')->where('period_type', 'month')->whereDate('period_start', $mFrom->toDateString())->where('metric', 'won_value')->value('target_value')
                    : (float) CrmTarget::whereIn('user_id', $scope)->where('period_type', 'month')->whereDate('period_start', $mFrom->toDateString())->where('metric', 'won_value')->sum('target_value'),
            ],
            'totals' => [
                'count' => $open->count(),
                'value' => round((float) $open->sum('value'), 2),
                'weighted' => round($open->sum(fn ($d) => $d->weightedValue()), 2),
            ],
        ];
    }

    /** Leaderboard: one row per rep (owner) in scope. */
    public function reps(?array $scope, Carbon $from, Carbon $to): array
    {
        $by = fn ($q, $agg = 'COUNT(*)') => $this->own($q, $scope)->whereNotNull('owner_id')
            ->select('owner_id', DB::raw("$agg as n"))->groupBy('owner_id')->pluck('n', 'owner_id');

        $newLeads = $by($this->leads()->whereBetween('created_at', [$from, $to]));
        $converted = $by($this->leads()->whereBetween('converted_at', [$from, $to]));
        $wonN = $by($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]));
        $wonV = $by($this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to]), 'SUM(value)');
        $lostN = $by($this->deals()->where('status', 'lost')->whereBetween('lost_at', [$from, $to]));
        $openV = $by($this->deals()->where('status', 'open'), 'SUM(value)');
        $acts = $this->own($this->acts()->where('status', 'done')->whereIn('type', self::WORK_TYPES)->whereBetween('done_at', [$from, $to]), $scope)
            ->whereNotNull('owner_id')->select('owner_id', 'type', DB::raw('COUNT(*) as n'))->groupBy('owner_id', 'type')->get()->groupBy('owner_id');

        $ids = collect([$newLeads, $converted, $wonN, $lostN, $openV])->flatMap(fn ($c) => $c->keys())->merge($acts->keys())
            ->unique()->filter()->values();
        if ($scope !== null) {
            $ids = $ids->merge($scope)->unique()->values();
        }
        $users = User::whereIn('id', $ids)->get()->keyBy('id');

        // Targets of the period when the range is exactly one month / quarter.
        $period = $this->periodOf($from, $to);
        $targets = $period ? $this->targetProgress($period, $from, $ids->all(), false)->groupBy('user_id') : collect();

        $rows = $ids->map(function ($id) use ($users, $newLeads, $converted, $wonN, $wonV, $lostN, $openV, $acts, $targets) {
            $won = (int) ($wonN[$id] ?? 0);
            $lost = (int) ($lostN[$id] ?? 0);
            $a = optional($acts->get($id))->pluck('n', 'type') ?? collect();
            $wonTarget = optional($targets->get($id))->firstWhere('metric', 'won_value');

            return [
                'id' => (int) $id,
                'name' => CrmLeadService::userName($users->get($id)) ?? '#' . $id,
                'new_leads' => (int) ($newLeads[$id] ?? 0),
                'converted_leads' => (int) ($converted[$id] ?? 0),
                'won_deals' => $won,
                'won_value' => round((float) ($wonV[$id] ?? 0), 2),
                'lost_deals' => $lost,
                'win_rate' => ($won + $lost) ? round($won / ($won + $lost) * 100, 1) : null,
                'open_value' => round((float) ($openV[$id] ?? 0), 2),
                'calls' => (int) ($a['call'] ?? 0),
                'meetings' => (int) ($a['meeting'] ?? 0),
                'emails' => (int) ($a['email'] ?? 0),
                'tasks' => (int) ($a['task'] ?? 0),
                'activities' => (int) $a->sum(),
                'target_percent' => $wonTarget['percent'] ?? null,
                'targets' => optional($targets->get($id))->values() ?? [],
            ];
        })->sortByDesc('won_value')->values();

        return ['rows' => $rows, 'period' => $period];
    }

    public function activities(?array $scope, Carbon $from, Carbon $to): array
    {
        $done = $this->own($this->acts()->where('status', 'done')->whereBetween('done_at', [$from, $to]), $scope);
        $byType = (clone $done)->select('type', DB::raw('COUNT(*) as n'))->groupBy('type')->pluck('n', 'type');
        $outcomes = (clone $done)->where('type', 'call')->whereNotNull('outcome')->where('outcome', '!=', '')
            ->select('outcome', DB::raw('COUNT(*) as n'))->groupBy('outcome')->pluck('n', 'outcome');

        $daily = $from->diffInDays($to) <= 62;
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';
        $raw = (clone $done)->select(DB::raw("DATE_FORMAT(done_at, '$fmt') as b"), 'type', DB::raw('COUNT(*) as n'))->groupBy('b', 'type')->get()->groupBy('b');
        $trend = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $daily ? $d->addDay() : $d->addMonthNoOverflow()->startOfMonth()) {
            $k = $daily ? $d->toDateString() : $d->format('Y-m');
            $row = ['d' => $k];
            foreach (['call', 'meeting', 'email', 'task', 'note'] as $type) {
                $row[$type] = (int) optional(optional($raw->get($k))->firstWhere('type', $type))->n;
            }
            $trend[] = $row;
        }

        $openQ = $this->own($this->acts()->where('status', 'open'), $scope);
        $users = User::whereIn('id', (clone $done)->whereNotNull('owner_id')->distinct()->pluck('owner_id'))->get()->keyBy('id');
        $byOwner = (clone $done)->whereNotNull('owner_id')->select('owner_id', 'type', DB::raw('COUNT(*) as n'))->groupBy('owner_id', 'type')->get()
            ->groupBy('owner_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => CrmLeadService::userName($users->get($id))]
                + collect(['call', 'meeting', 'email', 'task', 'note'])->mapWithKeys(fn ($t) => [$t => (int) optional($g->firstWhere('type', $t))->n])->all()
                + ['total' => (int) $g->sum('n')])
            ->sortByDesc('total')->values();

        return [
            'by_type' => $byType,
            'total' => (int) $byType->sum(),
            'outcomes' => $outcomes,
            'trend' => $trend,
            'by_owner' => $byOwner,
            'open' => (clone $openQ)->count(),
            'overdue' => (clone $openQ)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'planned_in_range' => $this->own($this->acts()->where('status', 'open')->whereBetween('due_at', [$from, $to]), $scope)->count(),
        ];
    }

    /** Lead sources, capture channels and web forms: leads in, converted, won value. */
    public function sources(?array $scope, Carbon $from, Carbon $to): array
    {
        $leadRows = fn (string $col) => $this->own($this->leads()->whereBetween('created_at', [$from, $to]), $scope)
            ->select($col . ' as k', DB::raw('COUNT(*) as n'),
                DB::raw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as conv'),
                DB::raw('SUM(CASE WHEN lost_at IS NOT NULL THEN 1 ELSE 0 END) as lost'),
                DB::raw('AVG(score) as score'), DB::raw('SUM(estimated_value) as est'))
            ->groupBy($col)->get();
        // Won deal value attributed to the lead's source / channel (deals won in the period).
        $wonBy = fn (string $col) => $this->own(CrmDeal::query()->whereNull('crm_deals.deleted_at')->where('crm_deals.status', 'won')
            ->whereBetween('crm_deals.won_at', [$from, $to])->join('crm_leads', 'crm_leads.id', '=', 'crm_deals.crm_lead_id'), $scope, 'crm_deals.owner_id')
            ->select('crm_leads.' . $col . ' as k', DB::raw('COUNT(*) as n'), DB::raw('SUM(crm_deals.value) as v'))->groupBy('crm_leads.' . $col)->get()->keyBy('k');

        $shape = function ($rows, $won, callable $name, callable $color) {
            return $rows->map(fn ($r) => [
                'key' => $r->k,
                'name' => $name($r->k),
                'color' => $color($r->k),
                'leads' => (int) $r->n,
                'converted' => (int) $r->conv,
                'lost' => (int) $r->lost,
                'conversion' => $r->n ? round($r->conv / $r->n * 100, 1) : null,
                'avg_score' => $r->score !== null ? round((float) $r->score) : null,
                'estimated' => round((float) $r->est, 2),
                'won_deals' => (int) optional($won->get($r->k))->n,
                'won_value' => round((float) optional($won->get($r->k))->v, 2),
            ])->sortByDesc('leads')->values();
        };

        $sources = CrmLeadSource::withTrashed()->get()->keyBy('id');
        $forms = CrmWebForm::withTrashed()->get()->keyBy('id');

        return [
            'sources' => $shape($leadRows('crm_lead_source_id'), $wonBy('crm_lead_source_id'),
                fn ($k) => optional($sources->get($k))->name, fn ($k) => optional($sources->get($k))->color ?? '#94a3b8'),
            'channels' => $shape($leadRows('captured_via'), $wonBy('captured_via'), fn ($k) => $k ?: 'manual', fn ($k) => null),
            'forms' => $shape($this->own($this->leads()->whereBetween('created_at', [$from, $to])->whereNotNull('crm_web_form_id'), $scope)
                ->select('crm_web_form_id as k', DB::raw('COUNT(*) as n'), DB::raw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as conv'),
                    DB::raw('SUM(CASE WHEN lost_at IS NOT NULL THEN 1 ELSE 0 END) as lost'), DB::raw('AVG(score) as score'), DB::raw('SUM(estimated_value) as est'))
                ->groupBy('crm_web_form_id')->get(), $wonBy('crm_web_form_id'), fn ($k) => optional($forms->get($k))->name, fn ($k) => optional($forms->get($k))->accent_color),
        ];
    }

    // ------------------------------------------------------------------

    /** 'month' / 'quarter' when [from, to] is exactly one of them, else null. */
    public function periodOf(Carbon $from, Carbon $to): ?string
    {
        foreach (CrmTarget::PERIODS as $type) {
            [$s, $e] = CrmTarget::bounds($type, $from);
            if ($s->toDateString() === $from->toDateString() && $e->toDateString() === $to->toDateString()) {
                return $type;
            }
        }

        return null;
    }

    private function own($q, ?array $scope, string $col = 'owner_id')
    {
        return $scope === null ? $q : $q->whereIn($col, $scope);
    }

    private function leads()
    {
        return CrmLead::query()->whereNull('crm_leads.deleted_at');
    }

    private function deals()
    {
        return CrmDeal::query()->whereNull('crm_deals.deleted_at');
    }

    private function acts()
    {
        return CrmActivity::query()->whereNull('crm_activities.deleted_at');
    }
}
