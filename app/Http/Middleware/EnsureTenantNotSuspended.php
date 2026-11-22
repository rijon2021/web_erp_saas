<?php

namespace App\Http\Middleware;

use App\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Only a live workspace serves its app, API, portal and storefront.
 *
 * - pending / provisioning / failed / rejected: the workspace (and its
 *   database) does not exist yet — show a status page instead of an error.
 * - suspended by an admin / cancelled: a "workspace unavailable" page.
 * - suspended for non-payment: the owner can still sign in and pay; every
 *   other app page leads to billing, and paying lifts the suspension.
 */
class EnsureTenantNotSuspended
{
    /** Paths a workspace suspended for non-payment keeps serving. */
    private const UNPAID_ALLOWED = [
        'login', 'login/*', 'logout',
        'password/*',
        'billing', 'billing/*',
        // SPA billing pages + what the SPA needs to boot them.
        'subscription', 'subscription/*', 'app/billing/*', 'app/subscription/*',
        'api/get_user_auth', 'api/translations/*', 'api/languages',
        'impersonate/*',
        'api/billing/*', 'api/password/*',
        // Mobile app: sign-in and session only, so it can show why it is locked.
        'api/mobile/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        $gated = [
            Tenant::STATUS_PENDING, Tenant::STATUS_PROVISIONING, Tenant::STATUS_FAILED,
            Tenant::STATUS_REJECTED, Tenant::STATUS_SUSPENDED, Tenant::STATUS_CANCELLED,
        ];
        if (! $tenant || ! in_array($tenant->status, $gated, true)) {
            return $next($request);
        }

        if ($tenant->isSuspendedForNonPayment()) {
            return $this->handleUnpaid($request, $next);
        }

        return $this->unavailable($request, (string) $tenant->status);
    }

    private function handleUnpaid(Request $request, Closure $next)
    {
        if ($request->is(...self::UNPAID_ALLOWED)) {
            return $next($request);
        }

        $message = __('central.WorkspaceUnpaidMessage');

        if ($request->expectsJson()) {
            // The SPA sends this status straight to /billing/plans.
            return response()->json(['status' => 'subscription_required', 'message' => $message], 403);
        }

        if (Auth::guard('web')->check()) {
            return redirect()->route('billing.plans')->with('error', $message);
        }

        if ($request->is('/')) {
            return redirect()->route('login');
        }

        return $this->unavailable($request, 'unpaid');
    }

    /**
     * @param string $state pending|provisioning|failed|rejected|suspended|cancelled|unpaid
     */
    private function unavailable(Request $request, string $state)
    {
        // Setup states are temporary (503); closed workspaces are refused (403).
        $code = in_array($state, ['pending', 'provisioning', 'failed'], true) ? 503 : 403;

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'workspace_unavailable',
                'state'   => $state,
                'message' => __('central.WorkspaceState_' . $state . '_title'),
            ], $code);
        }

        return response()->view('tenant_status', ['state' => $state], $code)
            ->header('Retry-After', $code === 503 ? '60' : '3600');
    }
}
