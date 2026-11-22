<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Pre-update backups: the application files as a series of part ZIPs
 * under files/ (built chunked so it can span several short requests), one
 * dump per database (central + every tenant,
 * under databases/), a copy of .env and a metadata file — all stored under
 * storage/app/updater/backups/<id>/ which is never web-accessible and
 * excluded from the update itself.
 */
class BackupManager
{
    public const FILES_DIR = 'files';
    public const DB_DIR = 'databases';
    public const DB_INDEX = 'databases.json';
    public const CENTRAL_DUMP = 'central.sql';
    public const ENV_COPY = 'env.backup';
    public const META = 'meta.json';
    public const MANIFEST = 'manifest.txt';

    /** Dump file name for a database target (central or tenant). */
    public static function dumpFileFor(?string $tenantId): string
    {
        if ($tenantId === null) {
            return self::CENTRAL_DUMP;
        }

        return 'tenant-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $tenantId).'.sql';
    }

    public static function dumpPath(string $backupId, string $file): string
    {
        return UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::DB_DIR.DIRECTORY_SEPARATOR.$file;
    }

    /** Persist the list of database targets a backup holds (key, tenant, label, file). */
    public function writeDatabaseIndex(string $backupId, array $databases): void
    {
        $dir = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::DB_DIR;
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $index = array_map(fn ($db) => [
            'key' => $db['key'],
            'tenant' => $db['tenant'],
            'label' => $db['label'],
            'file' => $db['file'],
        ], $databases);
        File::put(UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::DB_INDEX, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function databaseIndex(string $backupId): array
    {
        $file = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::DB_INDEX;
        if (! File::exists($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    public function newBackupId(string $version): string
    {
        return date('Ymd-His').'-v'.preg_replace('/[^0-9a-zA-Z._-]/', '', $version ?: 'unknown');
    }

    public function createBackupDir(string $id): string
    {
        $dir = UpdaterPaths::backupDir($id);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Build the list of files to include in the application backup ZIP and
     * persist it (one relative path per line) into the backup dir. Returns
     * the number of files.
     */
    public function buildManifest(string $backupId): int
    {
        @set_time_limit(0);
        $base = base_path();
        $excluded = UpdaterPaths::backupExcludedPaths();
        $manifestPath = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::MANIFEST;

        $out = fopen($manifestPath, 'wb');
        if (! $out) {
            throw new \RuntimeException('Cannot create backup manifest');
        }
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    function ($item) use ($base, $excluded) {
                        $rel = str_replace('\\', '/', ltrim(substr($item->getPathname(), strlen($base)), '/\\'));
                        foreach ($excluded as $ex) {
                            if ($rel === $ex || strpos($rel, $ex.'/') === 0) {
                                return false;
                            }
                        }

                        return true;
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', ltrim(substr($item->getPathname(), strlen($base)), '/\\'));
                fwrite($out, $rel."\n");
                $count++;
            }
        } finally {
            fclose($out);
        }

        return $count;
    }

    /**
     * Tenant migration names shipped by the code in a backup — read from its
     * manifest, so no archive has to be opened.
     */
    public function tenantMigrationsInBackup(string $backupId): array
    {
        $manifest = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::MANIFEST;
        $names = [];
        $handle = @fopen($manifest, 'rb');
        if (! $handle) {
            return $names;
        }
        while (($line = fgets($handle)) !== false) {
            if (preg_match('#^database/migrations/tenant/([^/]+\.php)$#', trim($line), $m)) {
                $names[] = PackageValidator::migrationName($m[1]);
            }
        }
        fclose($handle);

        return $names;
    }

    /** The backup's application-file archives, in order (files/part-0001.zip …). */
    public function filesParts(string $backupId): array
    {
        $parts = File::glob(UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::FILES_DIR.DIRECTORY_SEPARATOR.'part-*.zip') ?: [];
        sort($parts, SORT_STRING);

        return $parts;
    }

    /**
     * Add files from the manifest to the backup archives starting at byte
     * $cursor['offset'], until $shouldStop() says to yield. Sets
     * $cursor['done'] when the whole manifest has been archived.
     */
    public function zipChunk(string $backupId, array &$cursor, callable $shouldStop): void
    {
        // Every batch of ~600 files becomes its own NEW part archive. One
        // growing ZIP is quadratic: ZipArchive rewrites the whole archive on
        // every close, so ~55k files (vendor included) meant re-copying the
        // archive ~90 times — 40+ minutes for a 150 MB backup.
        while (empty($cursor['done']) && ! $shouldStop()) {
            $this->zipBatch($backupId, $cursor, $shouldStop);
        }
    }

    private function zipBatch(string $backupId, array &$cursor, callable $shouldStop): void
    {
        $dir = UpdaterPaths::backupDir($backupId);
        $partsDir = $dir.DIRECTORY_SEPARATOR.self::FILES_DIR;
        if (! File::exists($partsDir)) {
            File::makeDirectory($partsDir, 0755, true);
        }
        // Part number and manifest position are committed only after the
        // part was closed: a request that dies mid-batch rewrites the same
        // part from the same offset next time.
        $partNo = (int) ($cursor['part'] ?? 0) + 1;
        $zipPath = $partsDir.DIRECTORY_SEPARATOR.sprintf('part-%04d.zip', $partNo);
        $manifestPath = $dir.DIRECTORY_SEPARATOR.self::MANIFEST;
        $base = base_path();

        $manifest = fopen($manifestPath, 'rb');
        if (! $manifest) {
            throw new \RuntimeException('Backup manifest missing');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fclose($manifest);
            throw new \RuntimeException('Cannot open backup archive for writing');
        }

        $offset = (int) ($cursor['offset'] ?? 0);
        $position = (int) ($cursor['position'] ?? 0);
        $done = false;
        $added = 0;
        try {
            fseek($manifest, $offset);
            while (true) {
                $line = fgets($manifest);
                if ($line === false) {
                    $done = true;
                    break;
                }
                $offset = ftell($manifest);
                $rel = trim($line);
                if ($rel === '') {
                    continue;
                }
                $full = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (File::exists($full) && ! $zip->addFile($full, $rel)) {
                    throw new \RuntimeException('Failed adding file to backup: '.$rel);
                }
                $added++;
                $position++;

                // ZipArchive may keep source files open until close(); cap
                // the batch to stay well below OS file-handle limits.
                if ($added >= 600 || ($added % 50 === 0 && $shouldStop())) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            fclose($manifest);
            $zip->close();
            @File::delete($zipPath);
            throw $e;
        }
        fclose($manifest);

        if ($added === 0) {
            // Only blank lines were left — no part to write.
            $zip->close();
            @File::delete($zipPath);
        } elseif ($zip->close() !== true) {
            throw new \RuntimeException('Failed finalizing backup archive part (disk full?)');
        } else {
            $cursor['part'] = $partNo;
        }
        $cursor['offset'] = $offset;
        $cursor['position'] = $position;
        if ($done) {
            $cursor['done'] = true;
        }
    }

    /** Re-open every finished part archive and verify the set against the manifest. */
    public function verifyFilesZip(string $backupId): bool
    {
        $dir = UpdaterPaths::backupDir($backupId);
        $manifestPath = $dir.DIRECTORY_SEPARATOR.self::MANIFEST;
        $parts = $this->filesParts($backupId);
        if (empty($parts) || ! File::exists($manifestPath)) {
            return false;
        }
        $zipCount = 0;
        foreach ($parts as $part) {
            $zip = new ZipArchive;
            if ($zip->open($part, ZipArchive::CHECKCONS) !== true) {
                return false;
            }
            $zipCount += $zip->numFiles;
            $zip->close();
        }

        // Manifest lines (files may legitimately be missing if deleted mid-backup;
        // allow a tiny tolerance but never a large shortfall)
        $manifestCount = 0;
        $handle = fopen($manifestPath, 'rb');
        while (fgets($handle) !== false) {
            $manifestCount++;
        }
        fclose($handle);

        return $zipCount > 0 && $zipCount >= (int) floor($manifestCount * 0.995);
    }

    /** Copy .env + metadata into the backup dir. */
    public function backupEnvAndMeta(string $backupId, array $meta): void
    {
        $dir = UpdaterPaths::backupDir($backupId);
        if (File::exists(base_path('.env'))) {
            File::copy(base_path('.env'), $dir.DIRECTORY_SEPARATOR.self::ENV_COPY);
        }
        $meta['created_at'] = date('Y-m-d H:i:s');
        $meta['php_version'] = PHP_VERSION;
        File::put($dir.DIRECTORY_SEPARATOR.self::META, json_encode($meta, JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------ management

    public function listBackups(): array
    {
        $root = UpdaterPaths::backups();
        if (! File::exists($root)) {
            return [];
        }
        $backups = [];
        foreach (File::directories($root) as $dir) {
            $id = basename($dir);
            $meta = [];
            $metaFile = $dir.DIRECTORY_SEPARATOR.self::META;
            if (File::exists($metaFile)) {
                $meta = json_decode((string) @file_get_contents($metaFile), true) ?: [];
            }
            $parts = $this->filesParts($id);
            $filesSize = array_sum(array_map(fn ($p) => File::size($p), $parts));
            $dbSize = 0;
            foreach (File::glob($dir.DIRECTORY_SEPARATOR.self::DB_DIR.DIRECTORY_SEPARATOR.'*.sql') ?: [] as $sql) {
                $dbSize += File::size($sql);
            }
            $index = $this->databaseIndex($id);
            $backups[] = [
                'id' => $id,
                'version' => $meta['version'] ?? null,
                'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', filemtime($dir)),
                'files_zip' => ! empty($parts),
                'files_size' => $filesSize,
                'db_dump' => File::exists(self::dumpPath($id, self::CENTRAL_DUMP)),
                'db_size' => $dbSize,
                'tenants' => count(array_filter($index, fn ($db) => ($db['tenant'] ?? null) !== null)),
                'tenants_skipped' => count($meta['skipped_tenants'] ?? []),
                'complete' => ! empty($meta['complete']),
            ];
        }
        usort($backups, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $backups;
    }

    /**
     * Delete one backup. Refuses to delete the only complete backup so a
     * recovery path always remains available.
     */
    public function deleteBackup(string $id): void
    {
        $id = basename($id); // never allow traversal via the id
        $dir = UpdaterPaths::backupDir($id);
        if (! File::exists($dir)) {
            throw new \RuntimeException(__('super.system_update.flash.backup_not_found'));
        }
        $complete = array_values(array_filter($this->listBackups(), fn ($b) => $b['complete']));
        if (count($complete) === 1 && ($complete[0]['id'] ?? null) === $id) {
            throw new \RuntimeException(__('super.system_update.flash.backup_last_complete'));
        }
        File::deleteDirectory($dir);
    }

    /** Apply the retention policy, always keeping at least one complete backup. */
    public function applyRetention(int $keep): void
    {
        $keep = max(1, $keep);
        $backups = $this->listBackups(); // newest first
        $complete = array_values(array_filter($backups, fn ($b) => $b['complete']));
        $keepIds = array_map(fn ($b) => $b['id'], array_slice($complete, 0, $keep));
        // Never end up with zero complete backups
        if (empty($keepIds) && ! empty($complete)) {
            $keepIds = [$complete[0]['id']];
        }
        foreach ($backups as $backup) {
            if (in_array($backup['id'], $keepIds, true)) {
                continue;
            }
            // Incomplete backups older than a day are debris; newest incomplete
            // may belong to a run in progress — leave it alone.
            if (! $backup['complete'] && strtotime($backup['created_at']) > time() - 86400) {
                continue;
            }
            try {
                File::deleteDirectory(UpdaterPaths::backupDir($backup['id']));
            } catch (\Throwable $e) {
            }
        }
    }

    public function markComplete(string $backupId): void
    {
        $metaFile = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::META;
        $meta = File::exists($metaFile) ? (json_decode((string) File::get($metaFile), true) ?: []) : [];
        $meta['complete'] = true;
        File::put($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
    }
}
