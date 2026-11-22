<?php

namespace App\Console\Commands;

use App\Services\Couriers\ShipmentSync;
use App\Services\TenantLimitsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Courier status sync across tenants: every tenant whose plan includes the
 * courier integration gets its due shipments checked (each tenant's own
 * interval decides what is due). Runs every 15 minutes from the scheduler.
 */
class CouriersSync extends Command
{
    protected $signature = 'couriers:sync {--tenant= : Only this tenant id} {--limit=200 : Max shipments per tenant per run}';

    protected $description = 'Fetch courier status updates for shipments still on the way';

    public function handle(): int
    {
        $tenants = \App\Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($tenants as $tenant) {
            try {
                tenancy()->initialize($tenant);
                app()->forgetInstance(TenantLimitsService::class);

                if (! Schema::hasTable('courier_accounts') || ! Schema::hasColumn('shipments', 'sync_failures')) {
                    continue;
                }
                // Couriers → Logs keeps 30 days.
                \App\Services\Couriers\CourierLogger::prune();
                if (! app(TenantLimitsService::class)->hasFeature('couriers')) {
                    continue;
                }

                $sync = app(ShipmentSync::class);
                $due = $sync->due((int) $this->option('limit'));
                $ok = $changed = $failed = 0;
                foreach ($due as $shipment) {
                    $r = $sync->syncOne($shipment);
                    $r['ok'] ? $ok++ : $failed++;
                    $changed += $r['changed'] ? 1 : 0;
                }

                if ($due->count()) {
                    $this->line("tenant {$tenant->id}: {$due->count()} checked, {$changed} updated, {$failed} failed");
                }
            } catch (\Throwable $e) {
                $this->warn("tenant {$tenant->id}: " . $e->getMessage());
                report($e);
            } finally {
                try {
                    tenancy()->end();
                } catch (\Throwable $e) {
                    // Nothing to clean up.
                }
            }
        }

        return self::SUCCESS;
    }
}
