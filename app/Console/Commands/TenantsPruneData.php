<?php

namespace App\Console\Commands;

use App\Models\Central\Plan;
use App\Services\Backup\TenantBackupStore;
use App\Services\Backup\TenantDataPruner;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily retention sweep.
 *
 * Per active tenant, under its plan's policy (Plan::RETENTION_FIELDS):
 *  - deletes logs, message history and temporary data past their retention
 *    (TenantDataPruner — business records are never touched);
 *  - deletes automatic backups past backup_retention_days, keeping the newest.
 *
 * Then the central database's own housekeeping (config/retention.php):
 * resolved system logs and old failed queue jobs.
 */
class TenantsPruneData extends Command
{
    protected $signature = 'tenants:prune-data
        {--tenant= : Tenant id(s), comma-separated. Default: every active tenant}
        {--dry-run : Count what would be deleted, delete nothing}
        {--skip-central : Leave the central database alone}';

    protected $description = 'Delete expired logs, message history, temporary data and old backups under each plan\'s retention policy';

    public function handle(): int
    {
        @ini_set('max_execution_time', '0');

        $dryRun = (bool) $this->option('dry-run');
        $wanted = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tenant')))));

        // Every tenant folder on disk, whatever the tenant's status: a suspended
        // tenant's domain still resolves for the public asset route.
        if (! $dryRun && ! $wanted) {
            $moved = TenantBackupStore::migrateAllLegacy();
            if ($moved > 0) {
                $this->line("Moved {$moved} backup file(s) out of public tenant storage.");
            }
        }

        $plans = Tenant::activePlans()->when($wanted, fn ($c) => $c->only($wanted));
        $failed = false;

        foreach (Tenant::whereIn('id', $plans->keys())->cursor() as $tenant) {
            /** @var Plan $plan */
            $plan = $plans[$tenant->id];

            try {
                $tenant->run(function () use ($tenant, $plan, $dryRun) {
                    $counts = (new TenantDataPruner)->prune($plan, $dryRun);

                    if (! $dryRun) {
                        // Backups written before they moved to private storage
                        // still sit on the publicly served disk — move them.
                        (new TenantBackupStore)->migrateLegacy();

                        $backups = (new TenantBackupStore)->pruneAuto($plan->retentionSetting('backup_retention_days'));
                        if ($backups > 0) {
                            $counts['backup files'] = $backups;
                        }
                    }

                    if ($counts) {
                        $this->line(sprintf('[%s]%s %s', $tenant->id, $dryRun ? ' would delete' : '',
                            collect($counts)->map(fn ($n, $t) => "{$t}: {$n}")->implode(', ')));
                    }
                });
            } catch (\Throwable $e) {
                // Tenant::run() has no finally: leave the failed tenant's context.
                tenancy()->end();
                $failed = true;
                Log::error('tenants:prune-data failed: '.$e->getMessage(), ['tenant' => $tenant->id]);
                $this->error("[{$tenant->id}] failed: {$e->getMessage()}");
            }
        }

        if (! $this->option('skip-central') && ! $wanted) {
            $this->pruneCentral($dryRun);
        }

        return $failed ? 1 : 0;
    }

    private function pruneCentral(bool $dryRun): void
    {
        $central = (string) config('tenancy.database.central_connection', 'central');
        $jobsConn = (string) config('queue.failed.database', $central);
        $jobsTable = (string) config('queue.failed.table', 'failed_jobs');

        $targets = [
            'system_logs (resolved)' => [$central, 'system_logs',
                fn ($q) => $q->where('status', 'resolved')
                    ->where('resolved_at', '<', now()->subDays((int) config('retention.central.resolved_system_logs_days', 90)))],
            'failed_jobs' => [$jobsConn, $jobsTable,
                fn ($q) => $q->where('failed_at', '<', now()->subDays((int) config('retention.central.failed_jobs_days', 30)))],
        ];

        foreach ($targets as $label => [$conn, $table, $scope]) {
            try {
                if (! Schema::connection($conn)->hasTable($table)) {
                    continue;
                }
                $query = $scope(DB::connection($conn)->table($table));
                $n = $dryRun ? $query->count() : $query->delete();
                if ($n > 0) {
                    $this->line(sprintf('[central]%s %s: %d', $dryRun ? ' would delete' : '', $label, $n));
                }
            } catch (\Throwable $e) {
                Log::warning("tenants:prune-data central {$label}: ".$e->getMessage());
            }
        }
    }
}
