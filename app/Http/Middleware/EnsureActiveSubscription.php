<?php

namespace App\Http\Middleware;

use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantSubscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /**
     * How long a grant is reused before the central database is asked again.
     * Subscription changes and natural expiry both take effect immediately
     * regardless (see handle()); this only bounds how long an entry lives.
     */
    private const GRANT_CACHE_SECONDS = 300;

    /**
     * POST endpoints that only read (or print) — still allowed during a
     * read-only grace period: signing out, previews, report queries, receipt
     * printing, the POS offline-sync status check.
     */
    private const READ_ONLY_POSTS = [
        'logout', 'api/logout',
        'api/*preview*',
        'api/report-questions/run',
        'api/pos/offline_sales/statuses',
        'api/direct_network_print/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if (! $tenant) {
            return $next($request);
        }

        // A recent grant is reused for up to GRANT_CACHE_SECONDS, so the
        // central database is not queried on every tenant request. Only a
        // grant is cached — never a denial, so a renewal unlocks at once — and
        // it never outlives the subscription window that granted it. The key
        // carries the tenant row's updated_at, which every subscription change
        // moves forward (TenantSubscription::bumpTenantVersion()), so an admin
        // cancelling or suspending takes effect on the very next request.
        $cacheKey = 'subscription_access_until:'.$tenant->getTenantKey().':'.$tenant->getRawOriginal('updated_at');
        $cachedUntil = Cache::get($cacheKey);
        if (is_int($cachedUntil) && $cachedUntil > time()) {
            return $next($request);
        }

        // A tenant can legitimately hold several candidate rows (a stale
        // trial plus a freshly activated paid row, a cancelled row still
        // inside its paid period, ...). Grant access if ANY of them does.
        $rows = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', TenantSubscription::LAPSABLE_STATUSES)
            ->get();

        $granted = false;
        $grantUntil = time();

        foreach ($rows as $row) {
            if ($row->grantsAccess()) {
                $granted = true;
                $rowEnds = $row->accessEndsAt();
                $grantUntil = max($grantUntil, $rowEnds ? $rowEnds->getTimestamp() : PHP_INT_MAX);
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
            $cacheUntil = min($grantUntil, time() + self::GRANT_CACHE_SECONDS);
            if ($cacheUntil > time()) {
                Cache::put($cacheKey, $cacheUntil, $cacheUntil - time());
            }

            return $next($request);
        }

        // Grace period: a paid period that ran out recently keeps the
        // workspace open (fully, or read-only) until the grace window closes.
        // Not cached — the read-only rule has to see every request anyway.
        $graceRow = TenantSubscription::where('tenant_id', $tenant->id)->inGrace()->latest('id')->first();
        if ($graceRow) {
            $readOnly = GeneralSetting::instance()->subscriptionGraceMode() === 'read_only';
            if (! $readOnly || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->is(...self::READ_ONLY_POSTS)) {
                return $next($request);
            }

            $message = __('central.GraceReadOnlyMessage', ['date' => $graceRow->graceEndsAt()?->format('M d, Y')]);
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'subscription_read_only',
                    'message' => $message,
                ], 403);
            }

            return back()->with('error', $message);
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
