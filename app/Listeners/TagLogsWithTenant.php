<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * Every tenant writes to the same central storage/logs/laravel.log. Tagging
 * each record with the tenant id (shared log context) lets the Error Logs
 * report import ONLY the current tenant's failures — see ErrorLogIngestor.
 */
class TagLogsWithTenant
{
    public function handle(TenancyInitialized|TenancyEnded $event): void
    {
        try {
            if ($event instanceof TenancyInitialized) {
                Log::shareContext(['tenant_id' => (string) $event->tenancy->tenant->getTenantKey()]);
            } else {
                Log::withoutContext(['tenant_id']);
                Log::flushSharedContext();
            }
        } catch (\Throwable $e) {
            // logging context must never break tenancy
        }
    }
}
