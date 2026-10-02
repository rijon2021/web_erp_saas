<?php

namespace App\Http\Middleware;

use App\Models\Central\TenantSubscription;
use App\Services\TenantLimitsService;
use Closure;
use Illuminate\Http\Request;

/**
 * Pause a tenant's OUTWARD-facing services (customer portal, Zapier, ...)
 * while the workspace has no subscription that grants access.
 *
 * These surfaces are used by the tenant's customers and integrations rather
 * than by staff, so they deliberately sit outside EnsureActiveSubscription
 * (no billing redirect makes sense for a customer). Without this gate an
 * expired trial kept serving them indefinitely. Access is judged by date via
 * TenantSubscription::scopeGrantingAccess(), so no cron is required.
 */
class EnsureTenantEntitled
{
    public function __construct(protected TenantLimitsService $limits)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if (! $tenant) {
            return $next($request);
        }

        if ($this->limits->getActivePlan()) {
            return $next($request);
        }

        // Keep the status labels honest while we are here (cron-free).
        try {
            TenantSubscription::expireLapsed($tenant->id);
        } catch (\Throwable) {
            // bookkeeping only
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status'  => 'subscription_required',
                'message' => 'This workspace\'s subscription is inactive. Service is paused until it is renewed.',
            ], 403);
        }

        abort(503, 'This service is temporarily unavailable.');
    }
}
