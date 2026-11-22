<?php

namespace App\Console\Commands;

use App\Models\Central\Plan;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\TenantBackupStore;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automatic tenant database backups, per the tenant's subscription plan
 * (Plan::RETENTION_FIELDS: backup_frequency, backup_retention_days).
 *
 * Scheduled hourly; each run backs up only the tenants that are due, so the
 * work spreads across the day instead of every tenant dumping at once. A
 * tenant's next backup falls due one interval after its last one finished.
 * Dumps are gzipped into the tenant's private storage (TenantBackupStore),
 * then backups older than the plan's retention are deleted — the newest
 * one is always kept.
 */
class TenantsBackup extends Command
{
    protected $signature = 'tenants:backup
        {--tenant= : Tenant id(s), comma-separated. Default: every active tenant whose plan schedules backups}
        {--force : Back up now, even if the last backup is recent}
        {--list : Show each tenant\'s schedule and whether it is due, then exit}';

    protected $description = 'Run the automatic tenant database backups that are due under each plan';

    /**
     * A run lands a little after the hour and a dump takes time, so "due"
     * allows this much slack — otherwise a daily backup would slip an hour
     * later every day.
     */
    private const SLACK_MINUTES = 30;

    public function handle(DatabaseDumper $dumper): int
    {
        @ini_set('max_execution_time', '0');

        $wanted = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tenant')))));

        $plans = Tenant::activePlans()
            ->when($wanted, fn ($c) => $c->only($wanted))
            ->filter(fn (Plan $plan) => $plan->backsUpAutomatically());

        if ($plans->isEmpty()) {
            $this->line('No tenant is on a plan with automatic backups.');

            return 0;
        }

        $minFreeMb = (int) config('retention.min_free_disk_mb', 1024);
        $failed = false;

        foreach (Tenant::whereIn('id', $plans->keys())->cursor() as $tenant) {
            /** @var Plan $plan */
            $plan = $plans[$tenant->id];

            try {
                $tenant->run(function () use ($tenant, $plan, $dumper, $minFreeMb, &$failed) {
                    $store = new TenantBackupStore;
                    $last = $store->newestAutoAt();
                    $due = $this->option('force') || $this->isDue($plan->retentionSetting('backup_frequency'), $last);

                    if ($this->option('list')) {
                        $this->line(sprintf('%s  %-7s  last: %-19s  %s',
                            $tenant->id, $plan->retentionSetting('backup_frequency'),
                            $last?->toDateTimeString() ?? 'never', $due ? 'DUE' : 'not due'));

                        return;
                    }

                    if (! $due) {
                        return;
                    }

                    $free = @disk_free_space(storage_path());
                    if ($free !== false && $free < $minFreeMb * 1024 * 1024) {
                        $failed = true;
                        Log::error('tenants:backup skipped: low disk space', ['tenant' => $tenant->id, 'free_mb' => (int) ($free / 1048576)]);
                        $this->error("[{$tenant->id}] skipped: less than {$minFreeMb} MB free on the backup disk.");

                        return;
                    }

                    DatabaseDumper::ensurePrivateDir($store->autoDir());
                    $path = $dumper->dumpCompressed(
                        (string) config('database.default'),
                        $store->autoDir().'/auto-backup-'.Carbon::now()->format('Y-m-d_His').'.sql',
                        'Tenant database'
                    );
                    $pruned = $store->pruneAuto($plan->retentionSetting('backup_retention_days'));

                    $this->info(sprintf('[%s] %s (%s KB)%s', $tenant->id, basename($path),
                        number_format(filesize($path) / 1024), $pruned ? ", removed {$pruned} old" : ''));
                });
            } catch (\Throwable $e) {
                // Tenant::run() has no finally: leave the failed tenant's context.
                tenancy()->end();
                $failed = true;
                Log::error('tenants:backup failed: '.$e->getMessage(), ['tenant' => $tenant->id]);
                $this->error("[{$tenant->id}] failed: {$e->getMessage()}");
            }
        }

        return $failed ? 1 : 0;
    }

    private function isDue(string $frequency, ?Carbon $last): bool
    {
        if ($last === null) {
            return true;
        }

        $next = match ($frequency) {
            'daily' => $last->copy()->addDay(),
            'weekly' => $last->copy()->addWeek(),
            'monthly' => $last->copy()->addMonthNoOverflow(),
            default => null,
        };

        return $next !== null && $next->subMinutes(self::SLACK_MINUTES)->lte(now());
    }
}
