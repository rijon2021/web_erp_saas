<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseDumper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dump the central database to storage/app/backups/central.
 *
 * The dump itself (mysqldump, or a pure-PHP fallback where exec() is disabled)
 * lives in DatabaseDumper. Every failure path prints an "ERROR_DETAILS:" line
 * that the settings page surfaces verbatim, and the command never lets an
 * exception escape (an uncaught one turned into an opaque "Server Error" toast).
 */
class CentralDatabaseBackup extends Command
{
    protected $signature = 'central:backup
        {--keep=0 : After a successful dump, delete all but the newest N backups (0 keeps everything)}';

    protected $description = 'Create a secured backup of the central database (retained, stored outside public).';

    public function handle(DatabaseDumper $dumper)
    {
        try {
            return $this->runBackup($dumper);
        } catch (\Throwable $e) {
            Log::error('central:backup failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->line('ERROR_DETAILS: ' . $e->getMessage());

            return 1;
        }
    }

    protected function runBackup(DatabaseDumper $dumper): int
    {
        $backupDir = storage_path('app/backups/central');
        DatabaseDumper::ensurePrivateDir($backupDir);

        $timestamp  = Carbon::now()->format('Y-m-d_His');
        $filename   = 'central-backup-' . $timestamp . '.sql';
        $outputPath = $backupDir . '/' . $filename;

        // The central DB is the tenancy central connection; fall back to the
        // app default when that name isn't configured.
        $conn = (string) config('tenancy.database.central_connection', config('database.default'));
        if (! config("database.connections.{$conn}")) {
            $conn = (string) config('database.default');
        }

        $method = $dumper->dump($conn, $outputPath, 'Central database');

        if ($dumper->problems) {
            Log::info('central:backup used the PHP dumper', ['reasons' => $dumper->problems]);
        }

        $this->line('BACKUP_METHOD: ' . $method);
        $this->line('BACKUP_FILE: ' . $filename);

        $keep = (int) $this->option('keep');
        if ($keep > 0) {
            $this->pruneOldBackups($backupDir, $keep);
        }

        // Leftovers of runs that were killed mid-dump (*.part, *.tmp option files).
        // (GLOB_BRACE is missing on some platforms; `*` skips dot-files.)
        $temporary = array_merge(glob($backupDir . '/*.part') ?: [], glob($backupDir . '/*.tmp') ?: [], glob($backupDir . '/.*.tmp') ?: []);
        foreach ($temporary as $stale) {
            if (is_file($stale) && (int) @filemtime($stale) < time() - 12 * 3600) {
                @unlink($stale);
            }
        }

        return 0;
    }

    /**
     * Keep the newest $keep dumps. The timestamp in the name sorts
     * chronologically, so a reverse name sort is newest first.
     */
    protected function pruneOldBackups(string $backupDir, int $keep): void
    {
        $files = glob($backupDir . '/central-backup-*.sql') ?: [];
        rsort($files, SORT_STRING);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
