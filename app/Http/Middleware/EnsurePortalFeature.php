<?php

namespace App\Http\Middleware;

use App\Support\PortalSettings;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Applies Client Portal → Portal Settings to the portal API.
 *
 *   portal.feature                      the portal itself must be switched on
 *   portal.feature:quotations           …and the Quotations module
 *   portal.feature:quotations,quotation_requests   …and clients may request quotes
 *
 * The portal SPA hides switched-off modules too, but this is the real gate.
 */
class EnsurePortalFeature
{
    public function handle($request, Closure $next, string ...$features)
    {
        if (! PortalSettings::enabled()) {
            return response()->json(['message' => __('portal.portal_unavailable')], 503);
        }

        // An account disabled by staff stops working on its next request, not
        // just on the next /me check. The 403 sends the SPA back to the login.
        $client = Auth::guard('portal')->user();
        if ($client && (int) $client->status !== 1) {
            Auth::guard('portal')->logout();

            return response()->json(['message' => __('portal.portal_disabled')], 403);
        }

        // 404, never 401/403: the portal SPA's http client treats any 401/403
        // as "signed out" and bounces to /portal/login — a switched-off
        // section must not log the customer out.
        if ($features && ! PortalSettings::allows(...$features)) {
            return response()->json(['message' => __('portal.feature_disabled')], 404);
        }

        return $next($request);
    }
}
