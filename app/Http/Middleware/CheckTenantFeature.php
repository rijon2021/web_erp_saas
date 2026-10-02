<?php

namespace App\Http\Middleware;

use App\Models\Central\TenantSubscription;
use App\Services\TenantLimitsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckTenantFeature
{
    protected TenantLimitsService $limits;

    public function __construct(TenantLimitsService $limits)
    {
        $this->limits = $limits;
    }

    /**
     * Usage: middleware('tenant.feature:pos')
     *        middleware('tenant.feature:hrm,accounting')  ← requires ANY of the listed features
     */
    public function handle(Request $request, Closure $next, string ...$features)
    {
        if (empty($features)) {
            return $next($request);
        }

        $plan = $this->limits->getActivePlan();

        // No subscription grants access right now (trial/paid period lapsed,
        // judged by date) → this is a subscription problem, not a plan-tier
        // one: lock the surface rather than "upgrade your plan".
        if (! $plan) {
            return $this->denyNoSubscription($request);
        }

        // Allow if the plan has at least one of the listed features
        foreach ($features as $feature) {
            if ($plan->hasFeature($feature)) {
                return $next($request);
            }
        }

        return $this->deny($request, $features);
    }

    /**
     * The workspace has no access-granting subscription. Staff go to billing
     * (same contract as EnsureActiveSubscription, so the SPA redirects too);
     * everyone else — storefront shoppers, guests — gets a plain "paused"
     * response instead of a live shop running on an expired trial.
     */
    protected function denyNoSubscription(Request $request): mixed
    {
        if ($tenant = tenant()) {
            try {
                TenantSubscription::expireLapsed($tenant->id);
            } catch (\Throwable) {
                // bookkeeping only — the lockout never depends on it
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'subscription_required',
                'message' => EnsureActiveSubscription::MESSAGE,
            ], 403);
        }

        if (Auth::guard('web')->check()) {
            return redirect()->route('billing.plans')->with('error', EnsureActiveSubscription::MESSAGE);
        }

        abort(503, 'This store is temporarily unavailable.');
    }

    protected function deny(Request $request, array $features): mixed
    {
        $label = $this->featureLabel($features[0] ?? '');

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'feature_unavailable',
                'message' => "The \"{$label}\" feature is not available in your current plan. Please upgrade to access it.",
            ], 403);
        }

        $message = "The \"{$label}\" feature is not available in your current plan. Please upgrade to access it.";

        // back() falls back to the site root when there is no referer, which
        // loops forever on a gated page that IS the root (root-domain
        // storefront) or on any directly-opened gated URL. Send those to the
        // dashboard instead.
        $previous = url()->previous();
        if ($previous === $request->fullUrl() || $previous === url('/')) {
            return redirect('/app/dashboard')->with('error', $message);
        }

        return redirect()->back()->with('error', $message);
    }

    protected function featureLabel(string $key): string
    {
        return \App\Models\Central\Plan::AVAILABLE_FEATURES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
