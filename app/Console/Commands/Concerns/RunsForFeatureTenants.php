<?php

namespace App\Console\Commands\Concerns;

use App\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Module commands (rental:*, crm:*) work on tenant data: run the body once
 * inside every active tenant whose plan carries the feature. A tenant whose
 * DB isn't migrated for the module yet is skipped quietly; any other failure
 * is logged and never stops the loop.
 */
trait RunsForFeatureTenants
{
    /** @param  callable(Tenant): void  $body */
    protected function forFeatureTenants(string $feature, callable $body): void
    {
        foreach (Tenant::activeWithFeature($feature) as $tenant) {
            // TenantLimitsService is a singleton that memoises the plan of the
            // first tenant it sees (WhatsApp quota etc.) — reset it per tenant.
            app()->forgetInstance(\App\Services\TenantLimitsService::class);
            try {
                $tenant->run(fn () => $body($tenant));
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Base table or view not found')) {
                    continue;
                }
                Log::warning("{$this->getName()} failed for tenant {$tenant->id}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                Log::warning("{$this->getName()} failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }
    }
}
