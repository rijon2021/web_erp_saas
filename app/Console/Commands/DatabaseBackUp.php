<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\TenantBackupStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * On-demand backup of the current tenant's database — the "Generate backup"
 * button on the tenant's Backup page. Only the newest manual backup is kept;
 * scheduled history comes from the plan's automatic backups (tenants:backup).
 *
 * Written to the tenant's private storage. This used to write into the
 * tenant's public disk, which is served without authentication.
 */
class DatabaseBackUp extends Command
{
    protected $signature = 'database:backup';

    protected $description = "Back up the current tenant's database to its private storage";

    public function handle(DatabaseDumper $dumper, TenantBackupStore $store)
    {
        // Outside a tenant the default connection is the central DB; dumping it
        // here (into whatever storage/ is) is exactly what must not happen.
        if (! tenancy()->initialized) {
            $this->line('ERROR_DETAILS: database:backup backs up a tenant database and must run inside a tenant. Use central:backup for the central database.');

            return 1;
        }

        // One manual backup at a time per tenant: two quick clicks would write
        // the same file, or one would delete the other's as "previous".
        // 15 minutes: long enough for a big dump, short enough that a request
        // killed mid-dump (no finally) doesn't block the button for an hour.
        $lock = Cache::lock('database-backup:'.tenant('id'), 900);
        if (! $lock->get()) {
            $this->line('ERROR_DETAILS: A backup is already being generated. Please wait for it to finish.');

            return 1;
        }

        try {
            $store->migrateLegacy();

            $dir = $store->manualDir();
            DatabaseDumper::ensurePrivateDir($dir);

            $filename = 'backup-'.Carbon::now()->format('Y-m-d_His').'.sql';
            $method = $dumper->dump((string) config('database.default'), $dir.'/'.$filename, 'Tenant database');

            // Keep only the backup just written.
            foreach (glob($dir.'/*.sql') ?: [] as $old) {
                if (basename($old) !== $filename) {
                    @unlink($old);
                }
            }

            $this->line('BACKUP_METHOD: '.$method);
            $this->line('BACKUP_FILE: '.$filename);

            return 0;
        } catch (\Throwable $e) {
            Log::error('database:backup failed: '.$e->getMessage(), ['tenant' => tenant('id')]);
            $this->line('ERROR_DETAILS: '.$e->getMessage());

            return 1;
        } finally {
            $lock->release();
        }
    }
}
