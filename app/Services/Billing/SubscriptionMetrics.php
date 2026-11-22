<?php

namespace App\Services\Billing;

use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Support\Collection;

/**
 * Platform subscription figures shared by the super-admin Dashboard and
 * Reports, so both show the same numbers.
 */
class SubscriptionMetrics
{
    /**
     * Monthly recurring revenue: one current paid, non-trial row per live
     * tenant that is set to keep renewing (active, period not over). Yearly
     * amounts count as amount / 12. Trials, free plans, cancellations running
     * out and lapsed rows (grace included) are not recurring revenue.
     */
    public function mrr(): float
    {
        $now = now();

        return (float) TenantSubscription::query()
            ->whereIn('tenant_id', Tenant::where('status', Tenant::STATUS_ACTIVE)->select('id'))
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->whereNull('trial_ends_at')
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('id')
            ->get(['id', 'tenant_id', 'amount', 'billing_cycle', 'currency'])
            ->unique('tenant_id')
            ->sum(function (TenantSubscription $s) {
                $monthly = $s->billing_cycle === 'yearly' ? (float) $s->amount / 12 : (float) $s->amount;

                return $this->inMainCurrency($monthly, (string) $s->currency);
            });
    }

    /**
     * A subscription amount in the main currency (multi-currency pricing:
     * workspaces may be billed in cedis, naira, …). Today's rate; an amount
     * whose rate is unavailable counts as is rather than breaking the widget.
     */
    protected function inMainCurrency(float $amount, string $currency): float
    {
        $main = strtoupper((string) \App\Models\Central\GeneralSetting::currencyCode());
        $currency = strtoupper($currency);
        if ($currency === '' || $currency === $main || $amount == 0.0) {
            return $amount;
        }
        try {
            return $amount * PlanPricing::rate($currency, $main);
        } catch (\Throwable) {
            return $amount;
        }
    }

    /** Memo for payingTenants(): the dashboard asks for it from several widgets and from churn(). */
    private ?int $payingTenants = null;

    /** Live tenants on a running paid (non-trial) subscription — the MRR population. */
    public function payingTenants(): int
    {
        return $this->payingTenants ??= TenantSubscription::query()
            ->whereIn('tenant_id', Tenant::where('status', Tenant::STATUS_ACTIVE)->select('id'))
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->whereNull('trial_ends_at')
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->distinct()
            ->count('tenant_id');
    }

    /**
     * Live tenants whose access ends within $days days (paid period or
     * trial), soonest first. Each item: tenant, subscription, ends_at, trial.
     */
    public function expiringSoon(int $days = 7, ?int $limit = null): Collection
    {
        $now = now();
        $until = $now->copy()->addDays($days);

        $rows = TenantSubscription::query()
            ->with(['plan', 'tenant.domains'])
            ->whereIn('tenant_id', Tenant::where('status', Tenant::STATUS_ACTIVE)->select('id'))
            ->where(function ($q) use ($now, $until) {
                $q->where(fn ($p) => $p->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_CANCELLED])
                                       ->whereNull('trial_ends_at')
                                       ->whereBetween('ends_at', [$now, $until]))
                  ->orWhere(fn ($t) => $t->where('status', TenantSubscription::STATUS_TRIAL)
                                         ->whereBetween('trial_ends_at', [$now, $until]));
            })
            ->get()
            ->map(fn (TenantSubscription $s) => [
                'tenant'       => $s->tenant,
                'subscription' => $s,
                'trial'        => $s->status === TenantSubscription::STATUS_TRIAL,
                'ends_at'      => $s->status === TenantSubscription::STATUS_TRIAL ? $s->trial_ends_at : $s->ends_at,
            ]);

        // A tenant that already renewed (another row running longer) is not
        // expiring — one query for all candidates, not one per row.
        $others = TenantSubscription::query()
            ->whereIn('tenant_id', $rows->pluck('subscription.tenant_id')->unique())
            ->grantingAccess(false)
            ->get(['id', 'tenant_id', 'status', 'ends_at', 'trial_ends_at'])
            ->groupBy('tenant_id');

        $rows = $rows
            ->reject(fn ($item) => ($others[$item['subscription']->tenant_id] ?? collect())
                ->contains(fn (TenantSubscription $o) => $o->id !== $item['subscription']->id
                    && (($o->status === TenantSubscription::STATUS_TRIAL ? $o->trial_ends_at : $o->ends_at) === null
                        || ($o->status === TenantSubscription::STATUS_TRIAL ? $o->trial_ends_at : $o->ends_at)->gt($item['ends_at']))))
            ->sortBy(fn ($item) => $item['ends_at']->getTimestamp())
            ->values();

        return $limit ? $rows->take($limit) : $rows;
    }

    /** Failed payments in the last $days days: [count, amount]. */
    public function failedPayments(int $days = 30): array
    {
        $query = TenantBillingPayment::on('central')
            ->where('status', TenantBillingPayment::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays($days));

        return [
            'count'  => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('amount'),
        ];
    }

    /**
     * Churn over the last $days days: paid subscriptions the customer
     * cancelled (counted by cancelled_at, whatever the row became since) plus
     * paid periods that ran out without renewal and were not replaced.
     * Rate = churned / (churned + paid subscriptions still running).
     */
    public function churn(int $days = 30): array
    {
        $since = now()->subDays($days);

        $cancelled = TenantSubscription::query()
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $since)
            ->where('amount', '>', 0)
            ->whereNull('trial_ends_at')
            // A plan-change request cancelled before it was paid (cancel()
            // turns those into "failed") was never revenue.
            ->where('status', '!=', TenantSubscription::STATUS_FAILED)
            ->pluck('tenant_id');

        $lapsed = TenantSubscription::query()
            ->where('status', TenantSubscription::STATUS_EXPIRED)
            ->whereNull('cancelled_at')
            ->whereNull('trial_ends_at')
            ->where('amount', '>', 0)
            ->whereBetween('ends_at', [$since, now()])
            ->pluck('tenant_id');

        // A tenant counts once, and only if nothing is running for it now
        // (a newer plan, a trial, or a cancellation still inside its period).
        $stillRunning = TenantSubscription::query()
            ->whereIn('tenant_id', $cancelled->merge($lapsed)->unique())
            ->grantingAccess(false)
            ->pluck('tenant_id')
            ->unique();

        $churned = $cancelled->merge($lapsed)->unique()->diff($stillRunning)->count();

        $paying = $this->payingTenants();

        return [
            'churned' => $churned,
            'rate'    => ($paying + $churned) > 0 ? round($churned / ($paying + $churned) * 100, 1) : 0.0,
        ];
    }
}
