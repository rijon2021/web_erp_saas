<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalContractService;
use App\Services\Rental\RentalMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps rental item statuses in step with the clock: a booked item flips to
 * "rented" when its contract starts, renewed contracts close at their end
 * date. The admin pages also sync on load, so this only matters for screens
 * nobody opens — hourly is plenty. Runs in every tenant whose plan includes
 * Rental Management.
 */
class RentalSyncStatuses extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'rental:sync-statuses';

    protected $description = 'Sync rental statuses, issue due invoices, apply late fees and open due preventive maintenance';

    public function handle(): int
    {
        $this->forFeatureTenants('rental', function ($tenant) {
            if (! Schema::hasTable('rental_maintenance_schedules')) {
                return;
            }
            $jobs = app(RentalMaintenanceService::class)->runSchedules();
            app(RentalContractService::class)->sync();
            app(RentalBillingService::class)->run();
            $this->info("[{$tenant->id}] Rental statuses synced, invoices issued, late fees applied, {$jobs} maintenance job(s) opened.");
        });

        return self::SUCCESS;
    }
}
