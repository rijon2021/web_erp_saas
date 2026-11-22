<?php

namespace App\Services\SuperDashboard;

use App\Models\Central\CentralUser;
use App\Models\Central\CustomDomain;
use App\Models\Central\GeneralSetting;
use App\Models\Central\ImpersonationLog;
use App\Models\Central\PaymentLink;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Models\Central\SubscriptionEvent;
use App\Models\Central\SupportTicket;
use App\Models\Central\SystemLog;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionMetrics;
use App\Support\SuperDashboard\WidgetRegistry;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builds the data for one super-admin dashboard widget at a time, so only the
 * widgets that are switched on cost a query. Each method is named after its
 * widget key in camelCase and returns null when there is nothing to show
 * (the widget is then skipped, leaving no gap in the grid).
 *
 * Chart widgets return ['title', 'icon', 'badge', 'spec']; spec is handed to
 * dashboard.js as-is. KPI widgets return the fields _kpi.blade.php reads.
 */
class WidgetData
{
    /** Categorical palette (teal first, matching the original charts). */
    private const PALETTE = ['#14B8A6', '#6366f1', '#f59e0b', '#3b82f6', '#ef4444', '#10b981', '#8b5cf6', '#ec4899', '#64748b', '#06b6d4'];

    private ?CentralUser $user = null;

    /** Per-request results shared by several widgets. */
    private array $memo = [];

    public function __construct(private SubscriptionMetrics $metrics)
    {
    }

    public function forUser(?CentralUser $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function build(string $key, array $options, int $cacheMinutes = 0): ?array
    {
        $method = Str::camel($key);
        if (! method_exists($this, $method)) {
            return null;
        }

        if ($cacheMinutes > 0 && ! empty(WidgetRegistry::get($key)['cacheable'])) {
            $cacheKey = 'super_dashboard:' . $key . ':' . app()->getLocale() . ':' . md5(json_encode($options));

            return Cache::remember($cacheKey, $cacheMinutes * 60, fn () => $this->{$method}($options));
        }

        return $this->{$method}($options);
    }

    // ════════════════════════════════════════════════════════════════
    //  KPI cards
    // ════════════════════════════════════════════════════════════════

    protected function kpiTenants(array $o): array
    {
        $total = Tenant::count();
        $active = Tenant::where('status', Tenant::STATUS_ACTIVE)->count();
        $newThisMonth = Tenant::where('created_at', '>=', now()->startOfMonth())->count();
        $churn = $this->churn();

        // Cumulative tenant count at the end of each of the last 8 months.
        $months = 8;
        $buckets = $this->monthBuckets($months);
        $perMonth = $this->tenantsPerMonth($months);
        $running = $total - array_sum($perMonth);
        $spark = [];
        foreach (array_keys($buckets) as $month) {
            $running += (int) ($perMonth[$month] ?? 0);
            $spark[] = $running;
        }

        return $this->kpi('indigo', 'bi-building', number_format($active), __('super.dashboard.kpi_active_tenants'), [
            'suffix'     => '/ ' . number_format($total),
            'delta'      => $newThisMonth > 0 ? '+' . number_format($newThisMonth) : null,
            'delta_tone' => 'up',
            'note'       => __('super.dashboard.kpi_tenants_note', ['new' => number_format($newThisMonth), 'churned' => number_format($churn['churned'])]),
            'spark'      => $spark,
            'link'       => route('super.tenants.index'),
        ]);
    }

    protected function kpiActiveSubscriptions(array $o): array
    {
        $count = TenantSubscription::whereIn('status', ['active', 'trial'])->count();

        return $this->kpi('emerald', 'bi-check-circle', number_format($count), __('super.dashboard.active_trial'), [
            'link' => route('super.subscriptions.index'), 'link_label' => __('super.dashboard.view_subscriptions'),
        ]);
    }

    protected function kpiPlans(array $o): array
    {
        return $this->kpi('blue', 'bi-tag-fill', number_format(Plan::where('is_active', true)->count()), __('super.dashboard.active_plans'), [
            'link' => route('super.plans.index'), 'link_label' => __('super.dashboard.manage_plans'),
        ]);
    }

    protected function kpiTotalSubscriptions(array $o): array
    {
        $counts = $this->subscriptionStatusCounts();

        return $this->kpi('amber', 'bi-pie-chart-fill', number_format(array_sum($counts)), __('super.dashboard.total_subscriptions'), [
            'detail' => ($counts['active'] ?? 0) . ' ' . __('super.dashboard.active') . ', ' . ($counts['trial'] ?? 0) . ' ' . __('super.dashboard.trial'),
        ]);
    }

    protected function kpiMrr(array $o): array
    {
        $churn = $this->churn();

        return $this->kpi('emerald', 'bi-graph-up-arrow', $this->moneyRound($this->mrr()), __('central.DashMrr'), [
            'delta'      => $churn['rate'] . '%',
            'delta_tone' => $churn['rate'] > 0 ? 'down' : 'flat',
            'note'       => __('super.dashboard.kpi_mrr_note', ['count' => number_format($churn['churned'])]),
            'spark'      => array_values($this->paidPerMonth(8)),
            'spark_title' => __('super.dashboard.spark_paid_revenue'),
            'link'       => $this->can('subscriptions') ? route('super.subscriptions.index') : null,
        ]);
    }

    protected function kpiExpiring(array $o): array
    {
        $count = $this->expiringSoon($o['ahead'])->where('trial', false)->count();

        return $this->kpi('amber', 'bi-alarm', number_format($count), __('super.dash_custom.kpi_expiring_label', ['days' => $o['ahead']]), [
            'link' => route('super.tenants.index', ['status' => 'expiring']), 'link_label' => __('super.dashboard.view_all_tenants'),
        ]);
    }

    protected function kpiTrialsEnding(array $o): array
    {
        $count = $this->expiringSoon($o['ahead'])->where('trial', true)->count();

        return $this->kpi('blue', 'bi-hourglass-split', number_format($count), __('super.dash_custom.kpi_trials_label', ['days' => $o['ahead']]), [
            'link' => route('super.tenants.index', ['status' => 'trial']), 'link_label' => __('super.dashboard.view_all_tenants'),
        ]);
    }

    protected function kpiFailedPayments(array $o): array
    {
        $failed = $this->metrics->failedPayments($o['days']);
        $amount = $this->money($failed['amount']);

        return $this->kpi('indigo', 'bi-x-octagon', number_format($failed['count']), __('super.dash_custom.kpi_failed_label', ['days' => $o['days']]),
            ['note' => $amount] + ($this->can('payments')
                ? ['link' => route('super.payments.index', ['status' => 'failed']), 'link_label' => $amount]
                : ['detail' => $amount]));
    }

    protected function kpiRevenueMonth(array $o): array
    {
        $now = now();
        $thisMonth = $this->paidBetween($now->copy()->startOfMonth(), $now);
        [$lastStart, $lastEnd] = $this->sameSpanLastMonth();
        $lastMonth = $this->paidBetween($lastStart, $lastEnd);

        // Running total per day of this month, for the sparkline.
        $query = TenantBillingPayment::on('central');
        $dayExpr = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT(paid_at, '%Y-%m-%d')",
            'pgsql'            => "to_char(paid_at, 'YYYY-MM-DD')",
            default            => "strftime('%Y-%m-%d', paid_at)",
        };
        $perDay = $query
            ->select(DB::raw($dayExpr . ' as day'), DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'))
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->where('paid_at', '>=', $now->copy()->startOfMonth())
            ->groupBy('day')
            ->pluck('revenue', 'day')
            ->all();
        $spark = [];
        $running = 0.0;
        for ($day = $now->copy()->startOfMonth(); $day->lte($now); $day->addDay()) {
            $running += (float) ($perDay[$day->format('Y-m-d')] ?? 0);
            $spark[] = round($running, 2);
        }

        return $this->kpi('emerald', 'bi-cash-stack', $this->moneyRound($thisMonth), __('super.dash_custom.kpi_revenue_month_label'), [
            'trend'  => $this->trend($thisMonth, $lastMonth),
            'note'   => __('super.dashboard.kpi_revenue_note', ['value' => $this->money($lastMonth)]),
            'spark'  => count($spark) > 1 ? $spark : null,
            'link'   => route('super.payments.index', ['status' => 'paid']),
        ]);
    }

    protected function kpiArpu(array $o): array
    {
        $paying = $this->paying();

        return $this->kpi('indigo', 'bi-person-badge', $this->money($paying > 0 ? $this->mrr() / $paying : 0), __('super.dash_custom.kpi_arpu_label'), [
            'detail' => trans_choice('super.dash_custom.paying_tenants', $paying, ['count' => $paying]),
        ]);
    }

    protected function kpiRenewalForecast(array $o): array
    {
        $now = now();
        $due = TenantSubscription::query()
            ->whereIn('tenant_id', Tenant::where('status', Tenant::STATUS_ACTIVE)->select('id'))
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->whereNull('trial_ends_at')
            ->whereNull('cancelled_at')
            ->where('amount', '>', 0)
            ->whereBetween('ends_at', [$now, $now->copy()->addDays($o['ahead'])])
            ->orderByDesc('id')
            ->get(['id', 'tenant_id', 'amount'])
            ->unique('tenant_id');

        return $this->kpi('blue', 'bi-arrow-repeat', $this->money((float) $due->sum('amount')), __('super.dash_custom.kpi_renewal_label', ['days' => $o['ahead']]), [
            'detail' => trans_choice('super.dash_custom.renewals_due', $due->count(), ['count' => $due->count()]),
        ]);
    }

    protected function kpiNewSignups(array $o): array
    {
        $count = Tenant::where('created_at', '>=', now()->startOfMonth())->count();
        $last = Tenant::whereBetween('created_at', $this->sameSpanLastMonth())->count();

        return $this->kpi('amber', 'bi-person-plus', number_format($count), __('super.dash_custom.kpi_signups_label'), [
            'trend'  => $this->trend($count, $last),
            'detail' => __('super.dash_custom.vs_last_month', ['value' => number_format($last)]),
            'link'   => $this->can('tenants') ? route('super.tenants.index') : null,
        ]);
    }

    protected function kpiConversion(array $o): array
    {
        $tenantIds = Tenant::where('created_at', '>=', now()->subDays($o['days']))
            ->whereNotIn('status', [Tenant::STATUS_REJECTED, Tenant::STATUS_FAILED])
            ->pluck('id');
        $converted = $tenantIds->isEmpty() ? 0 : TenantBillingPayment::on('central')
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->where('amount', '>', 0)
            ->distinct()
            ->count('tenant_id');
        $rate = $tenantIds->count() > 0 ? round($converted / $tenantIds->count() * 100, 1) : 0;

        // Conversion per sign-up month over the last 6 months, for the sparkline.
        $months = 6;
        $recent = Tenant::where('created_at', '>=', $this->bucketStart($months))
            ->whereNotIn('status', [Tenant::STATUS_REJECTED, Tenant::STATUS_FAILED])
            ->get(['id', 'created_at']);
        $paidIds = $recent->isEmpty() ? collect() : TenantBillingPayment::on('central')
            ->whereIn('tenant_id', $recent->pluck('id'))
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->where('amount', '>', 0)
            ->distinct()
            ->pluck('tenant_id')
            ->flip();
        $spark = [];
        foreach (array_keys($this->monthBuckets($months)) as $month) {
            $cohort = $recent->filter(fn ($t) => $t->created_at->format('Y-m') === $month);
            $spark[] = $cohort->isEmpty() ? 0 : round($cohort->filter(fn ($t) => isset($paidIds[$t->id]))->count() / $cohort->count() * 100, 1);
        }
        $paying = $this->paying();

        return $this->kpi('emerald', 'bi-funnel', $rate . '%', __('super.dash_custom.kpi_conversion_label', ['days' => $o['days']]), [
            'note'  => __('super.dash_custom.converted_of', ['converted' => $converted, 'total' => $tenantIds->count()])
                . ' · ' . __('super.dashboard.kpi_arpu_short', ['value' => $this->money($paying > 0 ? $this->mrr() / $paying : 0)]),
            'spark' => $spark,
            'spark_title' => __('super.dashboard.spark_conversion'),
        ]);
    }

    protected function kpiOpenTickets(array $o): array
    {
        $open = SupportTicket::whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PENDING]);
        $unread = $this->unreadTickets();

        return $this->kpi('indigo', 'bi-life-preserver', number_format($open->count()), __('super.dash_custom.kpi_tickets_label'), [
            'detail'     => __('super.dash_custom.awaiting_reply', ['count' => $unread]),
            'note'       => __('super.dash_custom.awaiting_reply', ['count' => $unread]),
            'link'       => route('super.support.index'),
            'link_label' => __('super.dash_custom.awaiting_reply', ['count' => $unread]),
        ]);
    }

    protected function kpiPendingPayments(array $o): array
    {
        $pending = $this->pendingOfflinePayments();
        $amount = $this->money($pending['amount']);

        return $this->kpi('amber', 'bi-bank', number_format($pending['count']), __('super.dash_custom.kpi_pending_payments_label'), [
            'link'       => route('super.payments.index', ['status' => 'pending']),
            'link_label' => $amount,
            'note'       => $amount,
        ]);
    }

    protected function kpiPaymentLinks(array $o): array
    {
        $active = PaymentLink::where('status', PaymentLink::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // Aliases must not collide with model columns/accessors (amount).
            ->selectRaw('count(*) as agg_count, COALESCE(SUM(amount), 0) as agg_amount')
            ->first();
        $amount = $this->money((float) ($active->agg_amount ?? 0));

        return $this->kpi('blue', 'bi-link-45deg', number_format((int) ($active->agg_count ?? 0)), __('super.dash_custom.kpi_links_label'), [
            'link'       => route('super.payment-links.index'),
            'link_label' => $amount,
            'note'       => $amount,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Charts
    // ════════════════════════════════════════════════════════════════

    protected function chartTenantGrowth(array $o): array
    {
        $buckets = $this->monthBuckets($o['months']);
        $counts = $this->tenantsPerMonth($o['months']);

        return $this->chart('chart_tenant_growth', $this->lastMonths($o['months']), $this->timeseries(
            'chart_tenant_growth', $o['chart'], array_values($buckets),
            [['name' => __('central.NewTenants'), 'data' => $this->fill($buckets, $counts)]], ['#14B8A6']
        ));
    }

    protected function chartSubscriptionStatus(array $o): array
    {
        $counts = $this->subscriptionStatusCounts();
        $labels = $values = $colors = [];
        $statusColors = ['active' => '#10b981', 'trial' => '#3b82f6', 'cancelled' => '#ef4444', 'suspended' => '#f59e0b', 'expired' => '#94a3b8'];
        foreach ($statusColors as $status => $color) {
            $count = (int) ($counts[$status] ?? 0);
            if ($count > 0 || in_array($status, ['active', 'trial'], true)) {
                $labels[] = __('super.status.' . $status);
                $values[] = $count;
                $colors[] = $color;
            }
        }

        $spec = $this->categorical('chart_subscription_status', $o['chart'], $labels, $values, __('central.Subscriptions'), $colors);
        if ($spec['kind'] === 'segments') {
            $paying = $this->paying();
            $churn = $this->churn();
            $cycles = TenantSubscription::query()
                ->where('status', TenantSubscription::STATUS_ACTIVE)
                ->whereNull('trial_ends_at')
                ->where('amount', '>', 0)
                ->select('billing_cycle', DB::raw('count(*) as count'))
                ->groupBy('billing_cycle')
                ->pluck('count', 'billing_cycle')
                ->all();
            $spec['stats'] = [
                ['value' => number_format($paying), 'label' => __('super.dashboard.stat_active_paid')],
                ['value' => $this->money($paying > 0 ? $this->mrr() / $paying : 0), 'label' => __('super.dashboard.stat_arpu')],
                ['value' => $churn['rate'] . '%', 'label' => __('super.dashboard.stat_churn_30')],
            ];
            $spec['extra_title'] = __('super.dashboard.billing_cycle_title');
            $spec['extra'] = $this->bars([
                __('super.dash_custom.cycle_monthly') => (int) ($cycles['monthly'] ?? 0),
                __('super.dash_custom.cycle_yearly')  => (int) ($cycles['yearly'] ?? 0),
            ], false, ['#018A00', '#2a78d6']);
        }

        return $this->chart('chart_subscription_status', __('super.dashboard.by_status_total', ['total' => number_format(array_sum($values))]), $spec)
            + ['link' => $this->can('subscriptions') ? route('super.subscriptions.index') : null];
    }

    protected function chartSubsByPlan(array $o): array
    {
        $byPlan = TenantSubscription::query()
            ->join('plans', 'tenant_subscriptions.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('count(tenant_subscriptions.id) as count'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('count')
            ->limit($o['top'])
            ->pluck('count', 'plan_name')
            ->all();

        return $this->chart('chart_subs_by_plan', __('super.common.top_n', ['count' => $o['top']]), $this->categorical(
            'chart_subs_by_plan', $o['chart'], array_keys($byPlan), array_map('intval', array_values($byPlan)), __('central.Subscriptions'),
            ['#14B8A6', '#2DD4BF', '#5EEAD4', '#99F6E4', '#CCFBF1', '#0d9488', '#0f766e', '#115e59', '#134e4a', '#042f2e']
        ));
    }

    protected function chartRevenueTrend(array $o): array
    {
        $months = $o['months'];
        $buckets = $this->monthBuckets($months);
        // Twice the window: the first half is the comparison period, and for
        // windows up to a year each month is also compared with the same month a year earlier.
        $sums = $this->paidPerMonth($months * 2);
        $current = array_map(fn ($v) => round((float) $v, 2), $this->fill($buckets, $sums));
        $series = [['name' => __('super.dash_custom.series_revenue'), 'data' => $current]];
        $colors = ['#018A00'];
        $dash = [0];
        if ($months <= 12) {
            $lastYear = [];
            foreach (array_keys($buckets) as $month) {
                $lastYear[] = round((float) ($sums[Carbon::parse($month . '-01')->subYear()->format('Y-m')] ?? 0), 2);
            }
            if (array_sum($lastYear) > 0) {
                $series[] = ['name' => __('super.dashboard.series_same_month_last_year'), 'data' => $lastYear];
                $colors[] = '#94a3b8';
                $dash[] = 5;
            }
        }
        $total = array_sum($current);
        $priorTotal = 0.0;
        foreach (array_keys($sums) as $month) {
            if (! isset($buckets[$month])) {
                $priorTotal += (float) $sums[$month];
            }
        }
        $change = $this->trend($total, $priorTotal);

        $spec = $this->timeseries('chart_revenue_trend', $o['chart'], array_values($buckets), $series, $colors, true);
        $spec['dash'] = $dash;
        $spec['stats'] = [
            ['value' => $this->moneyRound($total), 'label' => $this->lastMonths($months)],
            ['value' => $change === null ? '—' : ($change >= 0 ? '+' : '') . $change . '%', 'label' => __('super.dashboard.stat_vs_prior', ['n' => $months]), 'tone' => $change === null ? null : ($change >= 0 ? 'up' : 'down')],
            ['value' => $this->moneyRound($months > 0 ? $total / $months : 0), 'label' => __('super.dashboard.stat_monthly_average')],
        ];

        return $this->chart('chart_revenue_trend', $this->lastMonths($months), $spec);
    }

    protected function chartNetGrowth(array $o): array
    {
        $buckets = $this->monthBuckets($o['months']);
        $since = $this->bucketStart($o['months']);

        $new = $this->tenantsPerMonth($o['months']);

        // Churn per month, on the same rules as SubscriptionMetrics::churn():
        // paid subscriptions cancelled by the customer, or paid periods that
        // lapsed without renewal. A tenant counts once per month.
        $cancelled = TenantSubscription::query()
            ->whereNotNull('cancelled_at')->where('cancelled_at', '>=', $since)
            ->where('amount', '>', 0)->whereNull('trial_ends_at')
            ->where('status', '!=', TenantSubscription::STATUS_FAILED)
            ->get(['tenant_id', 'cancelled_at'])
            ->map(fn ($s) => [$s->tenant_id, $s->cancelled_at->format('Y-m')]);
        $lapsed = TenantSubscription::query()
            ->where('status', TenantSubscription::STATUS_EXPIRED)
            ->whereNull('cancelled_at')->whereNull('trial_ends_at')
            ->where('amount', '>', 0)
            ->whereBetween('ends_at', [$since, now()])
            ->get(['tenant_id', 'ends_at'])
            ->map(fn ($s) => [$s->tenant_id, $s->ends_at->format('Y-m')]);
        $churned = $cancelled->merge($lapsed)
            ->unique(fn ($pair) => $pair[0] . '|' . $pair[1])
            ->countBy(fn ($pair) => $pair[1])
            ->all();

        return $this->chart('chart_net_growth', $this->lastMonths($o['months']), $this->timeseries(
            'chart_net_growth', 'bar', array_values($buckets), [
                ['name' => __('super.dash_custom.series_new'), 'data' => $this->fill($buckets, $new)],
                ['name' => __('super.dash_custom.series_churned'), 'data' => $this->fill($buckets, $churned)],
            ], ['#2a78d6', '#eb6834']
        ));
    }

    protected function chartRevenueByGateway(array $o): array
    {
        $rows = $this->paidPayments($o['range'])
            ->select('gateway', DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'))
            ->groupBy('gateway')
            ->orderByDesc('revenue')
            ->pluck('revenue', 'gateway')
            ->all();
        $labels = array_map(fn ($g) => TenantBillingPayment::GATEWAYS[$g] ?? ucfirst((string) ($g ?: '—')), array_keys($rows));

        return $this->chart('chart_revenue_by_gateway', $this->rangeLabel($o['range']), $this->categorical(
            'chart_revenue_by_gateway', $o['chart'], $labels, array_map(fn ($v) => round((float) $v, 2), array_values($rows)),
            __('super.dash_custom.series_revenue'), self::PALETTE, true
        ));
    }

    protected function chartRevenueByPlan(array $o): array
    {
        $rows = $this->paidPayments($o['range'])
            ->leftJoin('plans', 'tenant_billing_payments.plan_id', '=', 'plans.id')
            ->select(DB::raw("COALESCE(plans.name, '—') as plan_name"), DB::raw('SUM(tenant_billing_payments.amount) as revenue'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->pluck('revenue', 'plan_name')
            ->all();

        $spec = $this->categorical('chart_revenue_by_plan', $o['chart'], array_keys($rows), array_map(fn ($v) => round((float) $v, 2), array_values($rows)),
            __('super.dash_custom.series_revenue'), ['#018A00'], true);
        if ($spec['kind'] === 'bars') {
            $byGateway = $this->paidPayments($o['range'])
                ->select('gateway', DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'))
                ->groupBy('gateway')
                ->orderByDesc('revenue')
                ->pluck('revenue', 'gateway')
                ->all();
            $gatewayRows = [];
            foreach ($byGateway as $gateway => $total) {
                $gatewayRows[TenantBillingPayment::GATEWAYS[$gateway] ?? ucfirst((string) ($gateway ?: '—'))] = (float) $total;
            }
            $extra = $this->bars($gatewayRows, true, ['#2a78d6']);
            foreach ($extra['rows'] as &$row) {
                $row['text'] = $row['share'] . '%';
            }
            unset($row);
            $spec['extra_title'] = __('super.dashboard.by_gateway');
            $spec['extra'] = $extra;
        }

        return $this->chart('chart_revenue_by_plan', $this->rangeLabel($o['range']), $spec);
    }

    protected function chartBillingCycle(array $o): array
    {
        $rows = TenantSubscription::query()
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->whereNull('trial_ends_at')
            ->where('amount', '>', 0)
            ->select('billing_cycle', DB::raw('count(*) as count'))
            ->groupBy('billing_cycle')
            ->pluck('count', 'billing_cycle')
            ->all();

        return $this->chart('chart_billing_cycle', null, $this->categorical(
            'chart_billing_cycle', $o['chart'],
            [__('super.dash_custom.cycle_monthly'), __('super.dash_custom.cycle_yearly')],
            [(int) ($rows['monthly'] ?? 0), (int) ($rows['yearly'] ?? 0)],
            __('central.Subscriptions'), ['#14B8A6', '#6366f1']
        ));
    }

    protected function chartTenantStatus(array $o): array
    {
        $counts = Tenant::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
        $colors = [
            Tenant::STATUS_ACTIVE => '#10b981', Tenant::STATUS_SUSPENDED => '#f59e0b', Tenant::STATUS_PENDING => '#3b82f6',
            Tenant::STATUS_PROVISIONING => '#06b6d4', Tenant::STATUS_CANCELLED => '#94a3b8', Tenant::STATUS_FAILED => '#ef4444',
            Tenant::STATUS_REJECTED => '#64748b',
        ];
        $labels = $values = $palette = [];
        foreach ($colors as $status => $color) {
            if (($counts[$status] ?? 0) > 0) {
                $labels[] = __('super.dash_custom.tenant_status.' . $status);
                $values[] = (int) $counts[$status];
                $palette[] = $color;
            }
        }

        return $this->chart('chart_tenant_status', null, $this->categorical(
            'chart_tenant_status', $o['chart'], $labels, $values, __('super.dash_custom.series_tenants'), $palette
        ));
    }

    // ════════════════════════════════════════════════════════════════
    //  Tables, alerts and panels
    // ════════════════════════════════════════════════════════════════

    protected function expiringTable(array $o): ?array
    {
        $rows = $this->expiringSoon($o['ahead'])->take($o['rows']);

        return $rows->isEmpty() ? null : ['rows' => $rows];
    }

    protected function healthAlerts(array $o): ?array
    {
        $inactive = Tenant::where('status', '!=', Tenant::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', now()->subDays(30)))
            ->count();
        $expired = TenantSubscription::where('status', TenantSubscription::STATUS_EXPIRED)->count();
        $suspended = Tenant::where('status', Tenant::STATUS_SUSPENDED)->count();

        if ($inactive + $expired + $suspended === 0) {
            return null;
        }

        return compact('inactive', 'expired', 'suspended');
    }

    protected function pendingTenants(array $o): ?array
    {
        $tenants = $this->pendingTenantList();

        return $tenants->isEmpty() ? null : ['tenants' => $tenants];
    }

    protected function quickActions(array $o): array
    {
        return [];
    }

    protected function needsAttention(array $o): array
    {
        $items = [];
        $add = function (string $permission, string $icon, string $color, int $count, string $label, string $url, string $desc = '') use (&$items) {
            if ($count > 0 && $this->can($permission)) {
                $items[] = compact('icon', 'color', 'count', 'label', 'url', 'desc');
            }
        };

        $add('tenants', 'bi-hourglass-split', 'amber', $this->pendingTenantList()->count(),
            __('super.dash_custom.attn_pending_tenants'), route('super.tenants.index', ['status' => 'pending']), __('super.dashboard.attn_pending_tenants_desc'));
        $add('payments', 'bi-bank', 'amber', $this->pendingOfflinePayments()['count'],
            __('super.dash_custom.attn_bank_transfers'), route('super.payments.index', ['status' => 'pending']), __('super.dashboard.attn_bank_transfers_desc'));
        $add('payments', 'bi-person-check', 'blue', PendingRegistration::where('gateway', 'offline')->where('status', PendingRegistration::STATUS_PROCESSING)->count(),
            __('super.dash_custom.attn_registrations'), route('super.payments.pending-registrations'), __('super.dashboard.attn_registrations_desc'));
        $add('support', 'bi-chat-dots', 'indigo', $this->unreadTickets(),
            __('super.dash_custom.attn_tickets'), route('super.support.index'), __('super.dashboard.attn_tickets_desc'));
        $add('payments', 'bi-x-octagon', 'red', $this->metrics->failedPayments(7)['count'],
            __('super.dash_custom.attn_failed_payments'), route('super.payments.index', ['status' => 'failed']), __('super.dashboard.attn_failed_payments_desc'));
        $add('tenants', 'bi-globe2', 'blue', CustomDomain::where('is_verified', false)->count(),
            __('super.dash_custom.attn_domains'), route('super.tenants.index'), __('super.dashboard.attn_domains_desc'));
        $add('logs_health', 'bi-bug', 'red', $this->criticalLogs(),
            __('super.dash_custom.attn_critical_logs'), route('super.logs-health.index'), __('super.dashboard.attn_critical_logs_desc'));

        return ['items' => $items];
    }

    protected function systemHealth(array $o): array
    {
        $backups = (new \App\Http\Controllers\Central\Super\BackupController())->listBackups();
        $lastBackup = $backups[0]['date'] ?? null;
        $backupAge = $lastBackup ? (int) floor(abs(Carbon::parse($lastBackup)->diffInDays(now()))) : null;

        $failedJobs = null;
        try {
            $table = config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database') ?: config('database.default');
            if (Schema::connection($connection)->hasTable($table)) {
                $failedJobs = DB::connection($connection)->table($table)->count();
            }
        } catch (\Throwable) {
            // Unknown queue setup: leave the row out rather than break the dashboard.
        }

        $diskFree = $diskTotal = null;
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(base_path());
            $diskFree = $free !== false ? $free : null;
            $total = @disk_total_space(base_path());
            $diskTotal = $total !== false ? $total : null;
        }

        return [
            'last_backup'    => $lastBackup,
            'backup_age'     => $backupAge,
            'critical_logs'  => $this->criticalLogs(),
            'warning_logs'   => SystemLog::visible()->unresolved()->where('severity', SystemLog::SEVERITY_WARNING)->count(),
            'failed_jobs'    => $failedJobs,
            'debug'          => (bool) config('app.debug'),
            'env'            => (string) config('app.env'),
            'php'            => PHP_VERSION,
            'laravel'        => app()->version(),
            'disk_free'      => $diskFree,
            'disk_total'     => $diskTotal,
            'version'        => is_file(base_path('version.txt')) ? trim((string) @file_get_contents(base_path('version.txt'))) : null,
        ];
    }

    protected function tableRecentPayments(array $o): array
    {
        return ['rows' => TenantBillingPayment::on('central')
            ->with(['tenant.domains', 'plan'])
            ->latest('id')
            ->limit($o['rows'])
            ->get()];
    }

    protected function tableRecentSignups(array $o): array
    {
        return ['rows' => Tenant::with(['domains', 'subscription.plan'])->latest()->limit($o['rows'])->get()];
    }

    protected function tableTopTenants(array $o): array
    {
        $totals = $this->paidPayments($o['range'])
            ->select('tenant_id', DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'), DB::raw('count(*) as payments'))
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->orderByDesc('revenue')
            ->limit($o['rows'])
            ->get();
        $tenants = Tenant::with('domains')->whereIn('id', $totals->pluck('tenant_id'))->get()->keyBy('id');

        return [
            'range' => $this->rangeLabel($o['range']),
            'rows'  => $totals->map(fn ($row) => [
                'tenant'   => $tenants[$row->tenant_id] ?? null,
                'tenant_id' => $row->tenant_id,
                'total'    => (float) $row->getRawOriginal('revenue'),
                'payments' => (int) $row->payments,
            ]),
        ];
    }

    protected function tableOpenTickets(array $o): array
    {
        return ['rows' => SupportTicket::with('tenant')
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PENDING])
            ->orderByDesc('admin_unread')
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->limit($o['rows'])
            ->get()];
    }

    protected function tableSubscriptionEvents(array $o): ?array
    {
        if (! SubscriptionEvent::available()) {
            return ['rows' => collect(), 'unavailable' => true];
        }
        $events = SubscriptionEvent::with('admin')->latest('id')->limit($o['rows'])->get();
        $tenants = Tenant::whereIn('id', $events->pluck('tenant_id')->unique())->get()->keyBy('id');

        return ['rows' => $events, 'tenants' => $tenants];
    }

    protected function tableSystemErrors(array $o): array
    {
        return ['rows' => SystemLog::visible()
            ->unresolved()
            ->whereIn('severity', [SystemLog::SEVERITY_CRITICAL, SystemLog::SEVERITY_WARNING])
            ->latest('occurred_at')
            ->limit($o['rows'])
            ->get()];
    }

    protected function tableImpersonations(array $o): array
    {
        return ['rows' => ImpersonationLog::latest('started_at')->limit($o['rows'])->get()];
    }

    // ════════════════════════════════════════════════════════════════
    //  Shared figures (memoized per request)
    // ════════════════════════════════════════════════════════════════

    private function mrr(): float
    {
        return $this->memo['mrr'] ??= $this->metrics->mrr();
    }

    private function churn(): array
    {
        return $this->memo['churn'] ??= $this->metrics->churn(30);
    }

    private function paying(): int
    {
        return $this->memo['paying'] ??= $this->metrics->payingTenants();
    }

    /**
     * Subscriptions ending within $days days. A shorter window is cut from a
     * wider one already loaded (the rows are the same, filtered on ends_at),
     * so the 7-day KPIs and the 14-day table share one lookup.
     */
    private function expiringSoon(int $days): Collection
    {
        if (isset($this->memo['expiring'][$days])) {
            return $this->memo['expiring'][$days];
        }
        foreach ($this->memo['expiring'] ?? [] as $loaded => $rows) {
            if ($loaded > $days) {
                $until = now()->addDays($days);

                return $this->memo['expiring'][$days] = $rows->filter(fn ($item) => $item['ends_at']->lte($until))->values();
            }
        }

        return $this->memo['expiring'][$days] = $this->metrics->expiringSoon($days);
    }

    /** ['Y-m' => revenue] for the last $months months (months without payments are absent). */
    private function paidPerMonth(int $months): array
    {
        return $this->memo['paid_per_month'][$months] ??= $this->fromWiderWindow('paid_per_month', $months) ?? (function () use ($months) {
            $query = TenantBillingPayment::on('central');

            return $query
                ->select(DB::raw($this->monthExpr('paid_at', $query->getConnection()->getDriverName()) . ' as month'), DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'))
                ->where('status', TenantBillingPayment::STATUS_PAID)
                ->where('paid_at', '>=', $this->bucketStart($months))
                ->groupBy('month')
                ->pluck('revenue', 'month')
                ->all();
        })();
    }

    /** ['Y-m' => new tenants] for the last $months months (months without sign-ups are absent). */
    private function tenantsPerMonth(int $months): array
    {
        return $this->memo['tenants_per_month'][$months] ??= $this->fromWiderWindow('tenants_per_month', $months) ?? Tenant::query()
            ->select(DB::raw($this->monthExpr('created_at', Tenant::query()->getConnection()->getDriverName()) . ' as month'), DB::raw('count(*) as count'))
            ->where('created_at', '>=', $this->bucketStart($months))
            ->groupBy('month')
            ->pluck('count', 'month')
            ->all();
    }

    /**
     * A per-month series already loaded for a wider window, cut down to the
     * last $months months; null when no wider window is in the memo.
     */
    private function fromWiderWindow(string $name, int $months): ?array
    {
        $from = $this->bucketStart($months)->format('Y-m');
        foreach ($this->memo[$name] ?? [] as $loaded => $values) {
            if ($loaded > $months) {
                return array_filter($values, fn ($month) => $month >= $from, ARRAY_FILTER_USE_KEY);
            }
        }

        return null;
    }

    private function unreadTickets(): int
    {
        return $this->memo['unread_tickets'] ??= SupportTicket::whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PENDING])
            ->where('admin_unread', true)
            ->count();
    }

    /** Offline (bank transfer) payments awaiting review: ['count' => int, 'amount' => float]. */
    private function pendingOfflinePayments(): array
    {
        return $this->memo['pending_offline'] ??= (function () {
            $row = TenantBillingPayment::on('central')
                ->where('status', TenantBillingPayment::STATUS_PENDING)
                ->where('gateway', 'offline')
                // Aliases must not collide with model columns/accessors (total, amount).
                ->selectRaw('count(*) as agg_count, COALESCE(SUM(amount), 0) as agg_amount')
                ->first();

            return ['count' => (int) ($row->agg_count ?? 0), 'amount' => (float) ($row->agg_amount ?? 0)];
        })();
    }

    private function criticalLogs(): int
    {
        return $this->memo['critical_logs'] ??= SystemLog::visible()->unresolved()->critical()->count();
    }

    private function pendingTenantList(): Collection
    {
        return $this->memo['pending_tenants'] ??= Tenant::with('domains')
            ->where('status', Tenant::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();
    }

    private function subscriptionStatusCounts(): array
    {
        return $this->memo['status_counts'] ??= TenantSubscription::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    // ════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════

    private function can(string $permission): bool
    {
        return $this->user?->hasPermission($permission) ?? false;
    }

    private function money(float $amount): string
    {
        return GeneralSetting::currencySymbol() . number_format($amount, 2);
    }

    /** Compact money for bar labels: 1.2k / 3.4M from a thousand up, cents below. */
    private function moneyCompact(float $amount): string
    {
        $abs = abs($amount);
        if ($abs >= 1000000) {
            return GeneralSetting::currencySymbol() . number_format($amount / 1000000, 1) . 'M';
        }
        if ($abs >= 1000) {
            return GeneralSetting::currencySymbol() . number_format($amount / 1000, 1) . 'k';
        }

        return $this->money($amount);
    }

    /** Whole-unit money for hero figures (cents only when the amount is under 1,000). */
    private function moneyRound(float $amount): string
    {
        return GeneralSetting::currencySymbol() . number_format($amount, abs($amount) >= 1000 ? 0 : 2);
    }

    private function kpi(string $color, string $icon, string $value, string $label, array $extra = []): array
    {
        return array_merge([
            'color'      => $color,
            'icon'       => $icon,
            'value'      => $value,
            'label'      => $label,
            'link'       => null,
            'link_label' => null,
            'detail'     => null,
            'trend'      => null,
            // Hero-card extras (quarter width and up): a secondary figure next
            // to the value, a change pill with its tone, a one-line note and
            // a sparkline series.
            'suffix'     => null,
            'delta'      => null,
            'delta_tone' => null,
            'note'       => null,
            'spark'      => null,
            'spark_title' => null,
        ], $extra);
    }

    /** Percentage change, or null when there is no base to compare with. */
    private function trend(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function paidBetween(Carbon $from, Carbon $to): float
    {
        return (float) TenantBillingPayment::on('central')
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->sum(DB::raw(TenantBillingPayment::revenueSql()));
    }

    /** Paid payments in the last $days days (0 = all time). */
    private function paidPayments(int $days)
    {
        return TenantBillingPayment::on('central')
            ->where('tenant_billing_payments.status', TenantBillingPayment::STATUS_PAID)
            ->when($days > 0, fn ($q) => $q->where('tenant_billing_payments.paid_at', '>=', now()->subDays($days)));
    }

    private function rangeLabel(int $days): string
    {
        return $days > 0 ? __('super.dash_custom.choice_days', ['n' => $days]) : __('super.dash_custom.choice_all_time');
    }

    private function lastMonths(int $months): string
    {
        return __('super.dash_custom.choice_months', ['n' => $months]);
    }

    private function bucketStart(int $months): Carbon
    {
        return now()->startOfMonth()->subMonthsNoOverflow($months - 1);
    }

    /** ['Y-m' => 'M Y'] for the last $months months, oldest first. */
    private function monthBuckets(int $months): array
    {
        $buckets = [];
        $start = $this->bucketStart($months);
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            $buckets[$month->format('Y-m')] = $month->translatedFormat('M Y');
        }

        return $buckets;
    }

    /** Values for each bucket (numeric, 0 where a month has no rows). */
    private function fill(array $buckets, array $values): array
    {
        return array_map(fn ($month) => 0 + ($values[$month] ?? 0), array_keys($buckets));
    }

    /**
     * Last month from its 1st up to the same point in time as now is into
     * this month, so month-to-date figures compare fairly.
     *
     * @return Carbon[]
     */
    private function sameSpanLastMonth(): array
    {
        $now = now();
        $elapsed = (int) $now->copy()->startOfMonth()->diffInSeconds($now, true);
        $start = $now->copy()->startOfMonth()->subMonthNoOverflow();

        return [$start, $start->copy()->addSeconds($elapsed)];
    }

    private function monthExpr(string $column, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'pgsql'            => "to_char({$column}, 'YYYY-MM')",
            default            => "strftime('%Y-%m', {$column})",
        };
    }

    private function chart(string $key, ?string $badge, array $spec): array
    {
        $definition = WidgetRegistry::get($key);

        return [
            'title' => __('super.dash_custom.widgets.' . $key . '.name'),
            'icon'  => $definition['icon'] ?? 'bi-bar-chart',
            'badge' => $badge,
            'spec'  => $spec,
        ];
    }

    /**
     * Rows for the HTML segment bar / horizontal bar renderings:
     * label, value, formatted value, share of the total and share of the largest.
     */
    private function bars(array $rows, bool $money, array $colors): array
    {
        $total = (float) array_sum($rows);
        $max = (float) max(array_merge([0], array_values($rows)));
        $out = [];
        $i = 0;
        foreach ($rows as $label => $value) {
            $out[] = [
                'label'  => (string) $label,
                'value'  => $value,
                'text'   => $money ? $this->moneyCompact((float) $value) : number_format((float) $value),
                'share'  => $total > 0 ? round($value / $total * 100) : 0,
                'of_max' => $max > 0 ? round($value / $max * 100) : 0,
                'color'  => $colors[$i % max(1, count($colors))] ?? '#94a3b8',
            ];
            $i++;
        }

        return ['rows' => $out, 'total' => $total, 'money' => $money];
    }

    private function timeseries(string $key, string $type, array $categories, array $series, array $colors, bool $money = false): array
    {
        $total = array_sum(array_map(fn ($s) => array_sum($s['data']), $series));

        return [
            'el'         => 'chart-' . $key,
            'kind'       => 'series',
            'type'       => $type,
            'categories' => $categories,
            'series'     => $series,
            'colors'     => $colors,
            'money'      => $money,
            'empty'      => $total == 0 && $key !== 'chart_tenant_growth',
        ];
    }

    private function categorical(string $key, string $type, array $labels, array $values, string $seriesName, array $colors, bool $money = false): array
    {
        if ($type === 'segments' || $type === 'bars') {
            $rows = [];
            foreach (array_values($labels) as $i => $label) {
                $rows[$label] = $values[$i] ?? 0;
            }
            $spec = $this->bars($rows, $money, array_values($colors));
            $spec['el'] = 'chart-' . $key;
            $spec['kind'] = $type;
            $spec['empty'] = array_sum($values) == 0;

            return $spec;
        }

        return [
            'el'         => 'chart-' . $key,
            'kind'       => 'categorical',
            'type'       => $type,
            'labels'     => array_values($labels),
            'values'     => array_values($values),
            'seriesName' => $seriesName,
            'colors'     => array_values($colors),
            'money'      => $money,
            'empty'      => array_sum($values) == 0,
        ];
    }
}
