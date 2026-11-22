<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        $now = Carbon::now();

        // ── KPI stats ────────────────────────────────────────
        $totalTenants       = Tenant::count();
        $activeTenants      = Tenant::where('status', Tenant::STATUS_ACTIVE)->count();
        $activeSubscriptions = TenantSubscription::whereIn('status', ['active', 'trial'])->count();
        // In the main currency (base_amount) — multi-currency payments add up correctly.
        $totalRevenue       = TenantBillingPayment::on('central')->where('status', 'paid')->sum(\Illuminate\Support\Facades\DB::raw(TenantBillingPayment::revenueSql()));

        // MRR and churn — shared with the dashboard (SubscriptionMetrics). Churn
        // counts cancellations by cancelled_at (the cron later relabels them
        // "expired") plus paid periods that lapsed without renewal.
        $metrics = app(\App\Services\Billing\SubscriptionMetrics::class);
        TenantSubscription::expireLapsed();
        $mrr = $metrics->mrr();
        $churnRate = $metrics->churn(30)['rate'];

        // ── Tenant status breakdown ──────────────────────────
        $tenantStatuses = Tenant::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // ── Subscription status breakdown ────────────────────
        $subscriptionStatuses = TenantSubscription::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // ── Tenant growth — last 12 months ───────────────────
        $driver = Tenant::query()->getConnection()->getDriverName();
        $dateFormat = match ($driver) {
            'mysql'  => "DATE_FORMAT(created_at, '%Y-%m')",
            'pgsql'  => "to_char(created_at, 'YYYY-MM')",
            default  => "strftime('%Y-%m', created_at)",
        };

        $tenantGrowthRaw = Tenant::query()
            ->select(DB::raw("{$dateFormat} as month"), DB::raw('count(*) as count'))
            ->where('created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->all();

        $tenantGrowth = collect();
        for ($i = 11; $i >= 0; $i--) {
            $key   = $now->copy()->subMonths($i)->format('Y-m');
            $label = $now->copy()->subMonths($i)->format('M Y');
            $tenantGrowth->push([
                'month' => $label,
                'count' => $tenantGrowthRaw[$key] ?? 0,
            ]);
        }

        // ── Revenue trend — last 12 months ───────────────────
        $revenueRaw = TenantBillingPayment::on('central')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->select(
                DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as month"),
                DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('revenue', 'month')
            ->all();

        $revenueTrend = collect();
        for ($i = 11; $i >= 0; $i--) {
            $key   = $now->copy()->subMonths($i)->format('Y-m');
            $label = $now->copy()->subMonths($i)->format('M Y');
            $revenueTrend->push([
                'month'   => $label,
                'revenue' => (float) ($revenueRaw[$key] ?? 0),
            ]);
        }

        // ── Subscriptions by plan ────────────────────────────
        $subscriptionsByPlan = TenantSubscription::query()
            ->join('plans', 'tenant_subscriptions.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('count(tenant_subscriptions.id) as count'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('count')
            ->get();

        // ── Top plans by revenue ─────────────────────────────
        $topPlansByRevenue = TenantBillingPayment::on('central')
            ->where('tenant_billing_payments.status', 'paid')
            ->join('plans', 'tenant_billing_payments.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('SUM(tenant_billing_payments.amount) as revenue'), DB::raw('COUNT(*) as transactions'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // ── Recent tenants ───────────────────────────────────
        $recentTenants = Tenant::with('domains')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('central.super.reports.index', compact(
            'totalTenants',
            'activeTenants',
            'activeSubscriptions',
            'totalRevenue',
            'mrr',
            'churnRate',
            'tenantStatuses',
            'subscriptionStatuses',
            'tenantGrowth',
            'revenueTrend',
            'subscriptionsByPlan',
            'topPlansByRevenue',
            'recentTenants'
        ));
    }
}
