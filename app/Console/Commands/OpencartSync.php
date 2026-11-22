<?php

namespace App\Console\Commands;

use App\Models\OpencartSetting;
use App\Services\Opencart\OrderSync;
use App\Services\Opencart\SyncService;
use App\Services\TenantLimitsService;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduled half of the OpenCart module. Webhooks do the work; this is
 * what keeps a missed one from mattering: orders changed since the last run,
 * and once an hour a full stock reconcile for the bulk operations the stock
 * observer cannot see. Sweeps every active tenant whose plan has the module.
 *
 *   php artisan opencart:sync                 every tenant
 *   php artisan opencart:sync --tenant=acme   one tenant, plan not checked
 */
class OpencartSync extends Command
{
    protected $signature = 'opencart:sync {--tenant= : Tenant id (comma-separated for several)}';

    protected $description = 'Pull changed OpenCart orders and reconcile stock for every connected store';

    private const RECONCILE_MINUTES = 60;

    public function handle(): int
    {
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        if (tenancy()->initialized) {
            return $this->syncCurrentTenant();
        }

        $wanted = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tenant')))));

        $query = Tenant::query();
        $wanted ? $query->whereIn('id', $wanted) : $query->where('status', Tenant::STATUS_ACTIVE);

        $exit = 0;

        foreach ($query->cursor() as $tenant) {
            try {
                $tenant->run(function () use ($wanted, &$exit) {
                    // A fresh instance per tenant: the service memoises the plan.
                    if (! $wanted && ! (new TenantLimitsService)->hasFeature('opencart')) {
                        return;
                    }

                    if ($this->syncCurrentTenant() !== 0) {
                        $exit = 1;
                    }
                });
            } catch (\Throwable $e) {
                // A tenant that has not run the migration does not use the module.
                if (str_contains($e->getMessage(), 'Base table or view not found')) {
                    continue;
                }

                $exit = 1;
                $this->error($tenant->id.': '.$e->getMessage());
                report($e);
            }
        }

        return $exit;
    }

    private function syncCurrentTenant(): int
    {
        $settings = OpencartSetting::query()->first();
        if (! $settings || ! $settings->enabled || ! $settings->isConfigured()) {
            return 0;
        }

        // The cache store is tenant-prefixed, so this is one tenant's lock.
        $lock = Cache::lock('opencart:sync', 3600);
        if (! $lock->get()) {
            return 0;
        }

        $sync = SyncService::make($settings);

        try {
            if ($settings->auto_import_orders) {
                $result = OrderSync::make($settings)->catchUp();
                $this->line((tenant('id') ?? '').' orders '.json_encode($result));
            }

            $due = ! $settings->last_stock_reconcile_at
                || $settings->last_stock_reconcile_at->diffInMinutes(now(), true) >= self::RECONCILE_MINUTES;

            if ($settings->auto_sync_stock && $due) {
                $updated = $sync->reconcileStock();
                $settings->forceFill(['last_stock_reconcile_at' => now()])->save();
                $this->line((tenant('id') ?? '').' stock '.$updated);
            }

            return 0;
        } catch (\Throwable $e) {
            $sync->log('scheduled', 'error', $e->getMessage());
            $this->error((tenant('id') ?? '').': '.$e->getMessage());

            return 1;
        } finally {
            $lock->release();
        }
    }
}
