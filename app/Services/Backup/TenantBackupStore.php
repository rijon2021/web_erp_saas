<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Where a tenant's database backups live, and the housekeeping around them.
 * Call only while tenancy is initialised: storage_path() is then suffixed per
 * tenant (storage/tenant{id}/...).
 *
 *   app/backups/manual  — "Generate backup" from the tenant's Backup page
 *   app/backups/auto    — plan-scheduled backups (tenants:backup), gzipped
 *
 * Both are private. Backups used to be written to app/public/backup, which
 * stancl's /tenancy/assets/{path} route serves without authentication, so a
 * guessable backup-YYYY-MM-DD.sql was downloadable by anyone who knew a
 * tenant's domain. migrateLegacy() moves anything still there.
 */
class TenantBackupStore
{
    /** Names this store ever writes; anything else is refused by find(). */
    private const NAME_PATTERN = '/^(backup|auto-backup)-[0-9][0-9_\-]*\.sql(\.gz)?$/';

    public function manualDir(): string
    {
        return storage_path('app/backups/manual');
    }

    public function autoDir(): string
    {
        return storage_path('app/backups/auto');
    }

    /** Move backups out of the old, publicly served folder. */
    public function migrateLegacy(): void
    {
        $legacy = storage_path('app/public/backup');
        if (! is_dir($legacy)) {
            return;
        }

        self::moveOutOfPublic($legacy, $this->manualDir());
    }

    /**
     * migrateLegacy() for every tenant storage folder on disk, run from the
     * central context (no tenant initialised): storage/tenant{id}/app/public/
     * backup → storage/tenant{id}/app/backups/manual.
     *
     * @return int Files moved (or, failing that, deleted).
     */
    public static function migrateAllLegacy(): int
    {
        if (tenancy()->initialized) {
            return 0; // storage_path() is already one tenant's — not ours to sweep
        }

        $base = config('tenancy.filesystem.suffix_base', 'tenant');
        $moved = 0;

        // Each tenant's folder, plus the central one: the old every-minute
        // schedule dumped the central DB into storage/app/public/backup.
        $legacyDirs = array_merge(
            glob(storage_path($base.'*/app/public/backup'), GLOB_ONLYDIR) ?: [],
            is_dir(storage_path('app/public/backup')) ? [storage_path('app/public/backup')] : []
        );

        foreach ($legacyDirs as $legacy) {
            $manual = $legacy === storage_path('app/public/backup')
                ? storage_path('app/backups/legacy')
                : dirname($legacy, 2).'/backups/manual';
            // One folder that can't be handled (permissions) must not stop
            // the sweep of every other tenant.
            try {
                $moved += self::moveOutOfPublic($legacy, $manual);
            } catch (\Throwable $e) {
                Log::error('Could not move backups out of public storage: '.$e->getMessage(), ['folder' => $legacy]);
            }
        }

        return $moved;
    }

    /**
     * Move every file of $legacy into $target (deleting it if it can't be
     * moved — a dump must never stay where it can be fetched), then remove
     * $legacy. Logs any file that could neither be moved nor deleted.
     *
     * @return int Files actually moved or deleted.
     */
    private static function moveOutOfPublic(string $legacy, string $target): int
    {
        $done = 0;
        foreach (glob($legacy.'/*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            DatabaseDumper::ensurePrivateDir($target);
            if (@rename($file, $target.'/'.basename($file)) || @unlink($file)) {
                $done++;
            } else {
                Log::error('Backup file left in publicly served storage — move or delete it by hand', ['file' => $file]);
            }
        }
        @rmdir($legacy);

        return $done;
    }

    /**
     * @return array<int, array{name: string, type: string, size: int, created_at: string}>
     *               newest first
     */
    public function list(): array
    {
        // Housekeeping only — never fail the Backup page or health check over it.
        try {
            $this->migrateLegacy();
        } catch (\Throwable $e) {
            Log::error('Could not move backups out of public storage: '.$e->getMessage(), ['tenant' => tenant('id')]);
        }

        $rows = [];
        foreach (['manual' => $this->manualDir(), 'auto' => $this->autoDir()] as $type => $dir) {
            foreach ($this->filesIn($dir) as $file) {
                $rows[] = [
                    'name' => basename($file),
                    'type' => $type,
                    'size' => (int) @filesize($file),
                    'created_at' => Carbon::createFromTimestamp((int) @filemtime($file), config('app.timezone'))->toDateTimeString(),
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    /** Absolute path of a backup by file name, or null if there is no such backup. */
    public function find(string $name): ?string
    {
        $name = basename($name);
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        foreach ([$this->manualDir(), $this->autoDir()] as $dir) {
            $path = $dir.'/'.$name;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Newest scheduled backup's time, or null when there is none yet. */
    public function newestAutoAt(): ?Carbon
    {
        $times = array_map(fn ($f) => (int) @filemtime($f), $this->filesIn($this->autoDir()));

        // Carbon 3 builds timestamps in UTC unless told otherwise.
        return $times ? Carbon::createFromTimestamp(max($times), config('app.timezone')) : null;
    }

    /**
     * Delete scheduled backups older than $days, always keeping the newest so a
     * tenant whose backups stopped (plan downgraded, dump failing) is never left
     * with none.
     *
     * @return int Files deleted.
     */
    public function pruneAuto(int $days): int
    {
        $this->removeStaleTemporaryFiles();

        $files = $this->filesIn($this->autoDir());
        usort($files, fn ($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
        array_shift($files);

        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $deleted = 0;
        foreach ($files as $file) {
            if ((int) @filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Leftovers of dumps that died mid-way (DatabaseDumper writes *.part and
     * *.tmp, renamed only when complete). Old enough that no dump can still be
     * writing them.
     */
    private function removeStaleTemporaryFiles(): void
    {
        $cutoff = now()->subHours(12)->getTimestamp();
        foreach ([$this->manualDir(), $this->autoDir()] as $dir) {
            // `*` skips dot-files, and the password option files are dot-files.
            foreach (array_merge(glob($dir.'/*.part') ?: [], glob($dir.'/*.tmp') ?: [], glob($dir.'/.*.tmp') ?: []) as $file) {
                if (is_file($file) && (int) @filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    /** @return string[] */
    private function filesIn(string $dir): array
    {
        return array_values(array_filter(
            glob($dir.'/*.sql*') ?: [],
            fn ($f) => is_file($f) && preg_match(self::NAME_PATTERN, basename($f))
        ));
    }
}
