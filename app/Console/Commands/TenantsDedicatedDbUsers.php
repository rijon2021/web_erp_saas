<?php

namespace App\Console\Commands;

use App\Services\TenantDatabaseUser;
use App\Tenant;
use Illuminate\Console\Command;

/**
 * Move existing auto-provisioned tenants off the shared central database
 * account onto their own MySQL users (new workspaces get one at provisioning).
 * Tenants with admin-provided credentials are skipped.
 */
class TenantsDedicatedDbUsers extends Command
{
    protected $signature = 'tenants:dedicated-db-users
                            {--tenant=* : Only these tenant ids}
                            {--dry-run : List the tenants that would be converted, change nothing}';

    protected $description = 'Give each auto-provisioned tenant its own MySQL user instead of the central account';

    public function handle(TenantDatabaseUser $service): int
    {
        $ids = (array) $this->option('tenant');
        $query = Tenant::query()->whereIn('status', ['active', 'suspended', 'cancelled']);
        if ($ids) {
            $query->whereIn('id', $ids);
        }

        $converted = 0;
        $failed = 0;

        foreach ($query->cursor() as $tenant) {
            /** @var Tenant $tenant */
            if ($tenant->hasDatabaseCredentials()) {
                continue; // admin-provided, or already on its own user
            }

            $database = $tenant->getEffectiveDatabaseCredentials()['database'] ?? null;
            if (! $database) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$tenant->id}: {$database} → " . TenantDatabaseUser::usernameFor($database));
                continue;
            }

            if ($service->create($tenant, $database)) {
                $converted++;
                $this->info("{$tenant->id}: now connects as {$tenant->tenancy_db_username}");
            } else {
                $failed++;
                $this->error("{$tenant->id}: {$service->lastError}");
            }
        }

        if (! $this->option('dry-run')) {
            $this->line("Converted: {$converted}, failed: {$failed}.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
