<?php

namespace App\Http\Middleware;

use App\Models\Central\TenantSubscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gate the tenant admin app (web SPA shell + /api) behind a subscription that
 * grants access RIGHT NOW, judged by date — a lapsed trial or paid period is
 * locked out the moment it ends, whether or not the scheduler cron runs.
 *
 * The status column is treated as a cached label: any row found lapsed here
 * is flipped to "expired" immediately, so the super admin panel shows the
 * truth on hosts without cron. The daily subscriptions:check-expiry command
 * remains the backstop for tenants who never come back (and for emails).
 */
class EnsureActiveSubscription
{
    public const MESSAGE = 'Your subscription is inactive or expired. Please renew to continue.';

    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if (! $tenant) {
            return $next($request);
        }

        // A tenant can legitimately hold several candidate rows (a stale
        // trial plus a freshly activated paid row, a cancelled row still
        // inside its paid period, ...). Grant access if ANY of them does.
        $rows = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', TenantSubscription::LAPSABLE_STATUSES)
            ->get();

        $granted = false;

        foreach ($rows as $row) {
            if ($row->grantsAccess()) {
                $granted = true;
                continue;
            }

            try {
                $row->expireIfLapsed();
            } catch (\Throwable $e) {
                // Never let bookkeeping break the request — the lockout below
                // does not depend on the status flip succeeding.
                Log::warning("Could not flag lapsed subscription {$row->id} as expired: {$e->getMessage()}");
            }
        }

        if ($granted) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'subscription_required',
                'message' => self::MESSAGE,
            ], 403);
        }

        return redirect()->route('billing.plans')->with('error', self::MESSAGE);
    }
}
