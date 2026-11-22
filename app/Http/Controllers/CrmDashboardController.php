<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM dashboard (phase 1: leads). Every figure is counted over the leads the
 * user may see (own + unassigned pool, or all with crm_view_all).
 */
class CrmDashboardController extends Controller
{
    public function __construct(private CrmLeadService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'dashboard', CrmLead::class);

        $leads = fn () => $this->service->visible($user);
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $prevStart = $monthStart->copy()->subMonthNoOverflow();
        $openIds = CrmLeadStatus::withTrashed()->where('kind', 'open')->pluck('id');
        $staleBefore = $now->copy()->subDays(max(1, (int) CrmSetting::current()->stale_lead_days));

        $newThisMonth = $leads()->where('created_at', '>=', $monthStart)->count();
        $newLastMonth = $leads()->whereBetween('created_at', [$prevStart, $monthStart])->count();
        $convertedMonth = $leads()->where('converted_at', '>=', $monthStart)->count();
        $lostMonth = $leads()->where('lost_at', '>=', $monthStart)->count();
        // Conversion over the last 90 days of decided leads.
        $since = $now->copy()->subDays(90);
        $won90 = $leads()->where('converted_at', '>=', $since)->count();
        $lost90 = $leads()->where('lost_at', '>=', $since)->count();

        $statuses = CrmLeadStatus::orderBy('sort_order')->get();
        $byStatus = $leads()->select('crm_lead_status_id', DB::raw('COUNT(*) as n'))->groupBy('crm_lead_status_id')->pluck('n', 'crm_lead_status_id');

        $sources = CrmLeadSource::withTrashed()->get()->keyBy('id');
        $bySource = $leads()->select('crm_lead_source_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as won'))
            ->groupBy('crm_lead_source_id')->get()
            ->map(fn ($r) => [
                'id' => $r->crm_lead_source_id,
                'name' => optional($sources->get($r->crm_lead_source_id))->name,
                'color' => optional($sources->get($r->crm_lead_source_id))->color ?? '#94a3b8',
                'count' => (int) $r->n,
                'converted' => (int) $r->won,
            ])->sortByDesc('count')->values();

        $owners = User::whereIn('id', $leads()->whereNotNull('owner_id')->distinct()->pluck('owner_id'))->get()->keyBy('id');
        $byOwner = $leads()->whereIn('crm_lead_status_id', $openIds)
            ->select('owner_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(estimated_value) as v'))
            ->groupBy('owner_id')->get()
            ->map(fn ($r) => [
                'id' => $r->owner_id,
                'name' => $r->owner_id ? CrmLeadService::userName($owners->get($r->owner_id)) : null,
                'count' => (int) $r->n,
                'value' => round((float) $r->v, 2),
            ])->sortByDesc('count')->values()->take(8);

        // Daily trend, last 30 days: new leads vs converted.
        $from = $now->copy()->subDays(29)->startOfDay();
        $created = $leads()->where('created_at', '>=', $from)->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as n'))
            ->groupBy(DB::raw('DATE(created_at)'))->pluck('n', 'd');
        $converted = $leads()->where('converted_at', '>=', $from)->select(DB::raw('DATE(converted_at) as d'), DB::raw('COUNT(*) as n'))
            ->groupBy(DB::raw('DATE(converted_at)'))->pluck('n', 'd');
        $trend = [];
        for ($d = $from->copy(); $d->lte($now); $d->addDay()) {
            $k = $d->toDateString();
            $trend[] = ['d' => $k, 'created' => (int) ($created[$k] ?? 0), 'converted' => (int) ($converted[$k] ?? 0)];
        }

        $recent = $leads()->with('status', 'source', 'owner')->orderByDesc('created_at')->limit(8)->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'lead_number' => $l->lead_number,
                'name' => $l->name,
                'company_name' => $l->company_name,
                'rating' => $l->rating,
                'estimated_value' => (float) $l->estimated_value,
                'status' => $l->status ? ['name' => $l->status->name, 'color' => $l->status->color] : null,
                'source' => optional($l->source)->name,
                'owner' => CrmLeadService::userName($l->owner),
                'created_at' => optional($l->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'kpis' => [
                'open' => $leads()->whereIn('crm_lead_status_id', $openIds)->count(),
                'open_value' => round((float) $leads()->whereIn('crm_lead_status_id', $openIds)->sum('estimated_value'), 2),
                'new_month' => $newThisMonth,
                'new_last_month' => $newLastMonth,
                'converted_month' => $convertedMonth,
                'lost_month' => $lostMonth,
                'conversion_rate' => ($won90 + $lost90) > 0 ? round($won90 / ($won90 + $lost90) * 100, 1) : null,
                'unassigned' => $leads()->whereIn('crm_lead_status_id', $openIds)->whereNull('owner_id')->count(),
                'hot' => $leads()->whereIn('crm_lead_status_id', $openIds)->where('rating', 'hot')->count(),
                'stale' => $leads()->whereIn('crm_lead_status_id', $openIds)
                    ->where(fn ($q) => $q->where('last_activity_at', '<', $staleBefore)->orWhereNull('last_activity_at'))->count(),
                'mine' => $leads()->whereIn('crm_lead_status_id', $openIds)->where('owner_id', $user->id)->count(),
            ],
            'by_status' => $statuses->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'kind' => $s->kind, 'count' => (int) ($byStatus[$s->id] ?? 0),
            ])->values(),
            'by_source' => $bySource,
            'by_owner' => $byOwner,
            'trend' => $trend,
            'recent' => $recent,
            'stale_days' => (int) CrmSetting::current()->stale_lead_days,
            'deals' => $user->can('view', \App\Models\CrmDeal::class) ? $this->dealFigures($user) : null,
            'targets' => $this->targets($user),
            'forecast' => $user->can('view', \App\Models\CrmDeal::class)
                ? collect(app(\App\Services\Crm\CrmReportService::class)->forecast(app(\App\Services\Crm\CrmReportService::class)->scopeFor($user))['months'])
                    ->filter(fn ($m) => ! in_array($m['key'], ['later', 'none'], true))->take(4)->values()
                : null,
            'can_reports' => $user->can('reports', CrmLead::class),
        ]);
    }

    /** This month's targets: mine, plus the team's for view-all users. */
    private function targets($user): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('crm_targets')) {
            return [];
        }
        $viewAll = $user->can('viewAll', CrmLead::class);
        [$start] = \App\Models\CrmTarget::bounds('month');
        $rows = app(\App\Services\Crm\CrmReportService::class)->targetProgress('month', $start, [(int) $user->id], $viewAll);

        return $rows->map(fn ($t) => $t + ['scope' => $t['user_id'] ? 'mine' : 'team'])->sortBy('scope')->values()->all();
    }

    /** Pipeline money: open value, weighted forecast, won this month, overdue. */
    private function dealFigures($user): array
    {
        $deals = fn () => app(\App\Services\Crm\CrmDealService::class)->visible($user);
        $open = $deals()->where('status', 'open')->with('stage')->get(['id', 'value', 'crm_pipeline_stage_id', 'expected_close_date']);
        $monthStart = Carbon::now()->startOfMonth();
        $won = $deals()->where('status', 'won')->where('won_at', '>=', $monthStart);
        $lost = $deals()->where('status', 'lost')->where('lost_at', '>=', $monthStart)->count();
        $wonCount = (clone $won)->count();

        return [
            'open' => $open->count(),
            'open_value' => round((float) $open->sum('value'), 2),
            'weighted' => round($open->sum(fn ($d) => $d->weightedValue()), 2),
            'won_month' => $wonCount,
            'won_month_value' => round((float) (clone $won)->sum('value'), 2),
            'lost_month' => $lost,
            'win_rate_month' => ($wonCount + $lost) > 0 ? round($wonCount / ($wonCount + $lost) * 100, 1) : null,
            'overdue' => $open->filter(fn ($d) => $d->isOverdue())->count(),
            'closing_month' => $open->filter(fn ($d) => $d->expected_close_date && $d->expected_close_date->isSameMonth(Carbon::now()))->count(),
        ];
    }
}
