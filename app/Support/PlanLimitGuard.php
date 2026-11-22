<?php

namespace App\Support;

use App\Models\Central\Plan;
use App\Services\TenantLimitsService;

/**
 * In-code twin of the `tenant.limit:<key>` middleware, for records created
 * outside their own resource route (a rental customer or a converted CRM lead
 * creating a customer). Same 403 `limit_reached` payload as
 * CheckTenantLimits::deny(), so the SPA shows the usual upgrade prompt.
 */
class PlanLimitGuard
{
    public static function abortIfReached(string $limitKey): void
    {
        $limits = app(TenantLimitsService::class);
        if (! $limits->hasReachedLimit($limitKey)) {
            return;
        }

        $label = Plan::AVAILABLE_LIMITS[$limitKey]['label'] ?? ucfirst(str_replace('_', ' ', $limitKey));
        $max = $limits->getMaxAllowed($limitKey);
        $current = $limits->getCurrentUsage($limitKey);

        abort(response()->json([
            'status' => 'limit_reached',
            'message' => "You have reached the maximum number of {$label} ({$current}/{$max}) allowed by your current plan. Please upgrade to add more.",
            'limit' => $limitKey,
            'current' => $current,
            'max' => $max,
        ], 403));
    }
}
