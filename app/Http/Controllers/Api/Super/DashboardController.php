<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\PendingRegistration;
use App\Models\Central\SupportTicket;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /** key => [label, days back (null = month to date), chart buckets] */
    private const PERIODS = [
        '7d'  => ['last 7 days', 7, 7],
        '30d' => ['last 30 days', 30, 10],
        '90d' => ['last 90 days', 90, 9],
        'mtd' => ['this month', null, 10],
    ];

    public function index(Request $request)
    {
        TenantSubscription::expireLapsed();

        $key = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : '30d';
        [$label, $days, $buckets] = self::PERIODS[$key];

        $end = now();
        $start = $days === null ? $end->copy()->startOfMonth() : $end->copy()->subDays($days)->startOfDay();
        $length = $start->diffInSeconds($end);
        $prevStart = $start->copy()->subSeconds($length);

        $revenue = $this->paidBetween($start, $end);
        $previous = $this->paidBetween($prevStart, $start);
        $change = $previous > 0 ? round(($revenue - $previous) / $previous * 100) : null;

        $now = now();
        $soon = $now->copy()->addDays(7);
        $user = Auth::guard('central')->user();

        $trialIds = TenantSubscription::where('status', TenantSubscription::STATUS_TRIAL)
            ->where('trial_ends_at', '>', $now)->select('tenant_id');

        $failed = TenantBillingPayment::where('status', TenantBillingPayment::STATUS_FAILED)
            ->where('created_at', '>=', $now->copy()->subDays(30));

        return response()->json([
            'admin_name' => $user->name,
            'period'     => $key,
            'revenue'    => [
                'label'        => $label,
                'amount'       => $revenue,
                'amount_label' => SuperPresenter::money($revenue),
                'change_pct'   => $change,
                'series'       => $this->series($start, $end, $buckets),
            ],
            'counts' => [
                'total'          => Tenant::count(),
                'active'         => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'new_this_month' => Tenant::where('status', Tenant::STATUS_ACTIVE)->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
                'trial'          => Tenant::whereIn('id', $trialIds)->count(),
                'trials_ending'  => TenantSubscription::where('status', TenantSubscription::STATUS_TRIAL)
                    ->whereBetween('trial_ends_at', [$now, $soon])->distinct()->count('tenant_id'),
                'suspended'      => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
                'unpaid'         => Tenant::where('status', Tenant::STATUS_SUSPENDED)
                    ->where('data->suspension_reason', Tenant::SUSPENDED_NON_PAYMENT)->count(),
                'pending_tenants'       => Tenant::where('status', Tenant::STATUS_PENDING)->count(),
                'pending_registrations' => PendingRegistration::where('gateway', 'offline')
                    ->where('status', PendingRegistration::STATUS_PROCESSING)->count(),
                'pending_payments' => TenantBillingPayment::where('status', TenantBillingPayment::STATUS_PENDING)->count(),
                'failed_payments'  => (clone $failed)->count(),
                'open_tickets'     => SupportTicket::whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PENDING])->count(),
                'unread_tickets'   => SupportTicket::where('admin_unread', true)->count(),
            ],
            'failed_payments_label' => SuperPresenter::money((clone $failed)->sum('amount')),
        ]);
    }

    private function paidBetween(Carbon $from, Carbon $to): float
    {
        return (float) TenantBillingPayment::where('status', TenantBillingPayment::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    /** Paid revenue split into $buckets equal slices of the period. */
    private function series(Carbon $start, Carbon $end, int $buckets): array
    {
        $length = max(1, $start->diffInSeconds($end));
        $values = array_fill(0, $buckets, 0.0);

        TenantBillingPayment::where('status', TenantBillingPayment::STATUS_PAID)
            ->whereBetween('paid_at', [$start, $end])
            ->get(['amount', 'paid_at'])
            ->each(function ($p) use (&$values, $start, $length, $buckets) {
                $i = (int) floor($start->diffInSeconds($p->paid_at) / $length * $buckets);
                $values[min($buckets - 1, max(0, $i))] += (float) $p->amount;
            });

        return array_map(fn ($v) => round($v, 2), $values);
    }
}
