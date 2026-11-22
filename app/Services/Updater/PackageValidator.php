<?php

namespace App\Services\Updater;

use App\Http\Middleware\EnsureNotUpdating;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Validates an uploaded update package and the environment — including
 * every tenant database — before any change is made. Every check is
 * reported; any failed "critical" check blocks the update.
 */
class PackageValidator
{
    /** PHP extensions the application cannot run without. */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'zip', 'ctype', 'json', 'curl', 'gd', 'fileinfo',
    ];

    /**
     * Entries the package must contain (relative to the package root) for
     * the update to be performable and resumable. The updater's own files
     * are mandatory: after the file swap the NEXT request runs the new
     * code, so the new code must still contain the updater.
     */
    public const REQUIRED_ENTRIES = [
        'version.txt',
        'artisan',
        'composer.json',
        'public/index.php',
        'vendor/autoload.php',
        // Marks the SaaS edition: a single-store Stocky package must never
        // be swapped over a multi-tenant platform.
        'config/tenancy.php',
        'app/Services/Updater/UpdateManager.php',
        'app/Http/Controllers/Central/Super/SystemUpdateController.php',
    ];

    public function validate(string $zipPath): array
    {
        $checks = [];
        $package = ['size' => File::exists($zipPath) ? File::size($zipPath) : 0];

        // ---- ZIP integrity -------------------------------------------------
        $zip = new ZipArchive;
        $openResult = File::exists($zipPath) ? $zip->open($zipPath, ZipArchive::CHECKCONS) : false;
        $zipOk = $openResult === true;
        $checks[] = $this->check('zip_integrity', $this->t('zip_integrity'), $zipOk, 'critical',
            $zipOk ? null : $this->t('zip_integrity_fail', ['code' => var_export($openResult, true)]));

        $entries = [];
        $rootPrefix = null;
        $forbidden = [];
        $traversal = [];
        $uncompressed = 0;
        $tenantMigrations = [];

        if ($zipOk) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat || ! isset($stat['name'])) {
                    continue;
                }
                $name = str_replace('\\', '/', $stat['name']);
                $entries[] = $name;
                $uncompressed += (int) ($stat['size'] ?? 0);

                if (strpos($name, '..') !== false || $name[0] === '/' || preg_match('/^[a-zA-Z]:\//', $name)) {
                    $traversal[] = $name;
                }
            }

            $rootPrefix = $this->detectRootPrefix($entries);
            $prefixLen = $rootPrefix ? strlen($rootPrefix) : 0;

            foreach ($entries as $name) {
                $rel = $prefixLen && strpos($name, $rootPrefix) === 0 ? substr($name, $prefixLen) : $name;
                if (UpdaterPaths::isProtected($rel)) {
                    $forbidden[] = $rel;
                }
                if (preg_match('#^database/migrations/tenant/([^/]+\.php)$#', $rel, $m)) {
                    $tenantMigrations[] = self::migrationName($m[1]);
                }
            }

            $package['files'] = count($entries);
            $package['uncompressed_size'] = $uncompressed;
            $package['root_prefix'] = $rootPrefix;

            $checks[] = $this->check('zip_no_traversal', $this->t('zip_no_traversal'), empty($traversal), 'critical',
                empty($traversal) ? null : $this->t('zip_no_traversal_fail', ['entries' => implode(', ', array_slice($traversal, 0, 5))]));
            // A full release ZIP may ship protected paths; they are never
            // extracted or swapped (FileDeployer skips them), so this only warns.
            $checks[] = $this->check('zip_no_forbidden', $this->t('zip_no_forbidden'), empty($forbidden), 'warning',
                empty($forbidden) ? null : $this->t('zip_no_forbidden_fail', ['count' => count($forbidden), 'paths' => implode(', ', $this->topLevelOf($forbidden))]));

            // ---- required structure ----------------------------------------
            $missing = [];
            foreach (self::REQUIRED_ENTRIES as $required) {
                if ($zip->locateName(($rootPrefix ?? '').$required) === false) {
                    $missing[] = $required;
                }
            }
            $structureOk = empty($missing);
            $checks[] = $this->check('package_structure', $this->t('package_structure'), $structureOk, 'critical',
                $structureOk ? null : $this->t('package_structure_fail', ['missing' => implode(', ', $missing)]));

            // ---- version ----------------------------------------------------
            $packageVersion = null;
            $idx = $zip->locateName(($rootPrefix ?? '').'version.txt');
            if ($idx !== false) {
                $packageVersion = trim((string) $zip->getFromIndex($idx));
            }
            $currentVersion = $this->currentVersion();
            $package['version'] = $packageVersion;
            $package['current_version'] = $currentVersion;
            $versionOk = $packageVersion && $currentVersion && version_compare($packageVersion, $currentVersion, '>');
            $checks[] = $this->check('version_compatibility',
                $this->t('version_compatibility', ['package' => $packageVersion ?: '?', 'current' => $currentVersion ?: '?']),
                (bool) $versionOk, 'critical',
                $versionOk ? null : ($packageVersion && $currentVersion && version_compare($packageVersion, $currentVersion, '=')
                    ? $this->t('version_same')
                    : $this->t('version_downgrade')));

            // ---- PHP requirement from the package's composer.json ----------
            $phpConstraintOk = true;
            $phpDetail = null;
            $composerIdx = $zip->locateName(($rootPrefix ?? '').'composer.json');
            if ($composerIdx !== false) {
                $composer = json_decode((string) $zip->getFromIndex($composerIdx), true);
                $constraint = $composer['require']['php'] ?? null;
                if ($constraint && preg_match('/(\d+\.\d+)/', $constraint, $m)) {
                    $phpConstraintOk = version_compare(PHP_VERSION, $m[1], '>=');
                    $phpDetail = $phpConstraintOk ? null : $this->t('php_version_fail', ['required' => $constraint, 'current' => PHP_VERSION]);
                }
            }
            $checks[] = $this->check('php_version', $this->t('php_version', ['version' => PHP_VERSION]), $phpConstraintOk, 'critical', $phpDetail);

            $zip->close();
        }

        // ---- PHP extensions ------------------------------------------------
        $missingExt = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn ($e) => ! extension_loaded($e)));
        $checks[] = $this->check('php_extensions', $this->t('php_extensions'), empty($missingExt), 'critical',
            empty($missingExt) ? null : $this->t('php_extensions_fail', ['extensions' => implode(', ', $missingExt)]));

        // ---- write permissions --------------------------------------------
        $unwritable = [];
        foreach ([base_path(), app_path(), base_path('bootstrap/cache'), base_path('routes'),
            base_path('vendor'), public_path(), UpdaterPaths::storage(), UpdaterPaths::storage('app'), UpdaterPaths::storage('framework')] as $p) {
            if (File::exists($p) && ! is_writable($p)) {
                $unwritable[] = $p;
            }
        }
        $checks[] = $this->check('permissions', $this->t('permissions'), empty($unwritable), 'critical',
            empty($unwritable) ? null : $this->t('permissions_fail', ['paths' => implode(', ', $unwritable)]));

        // ---- databases (central + every tenant) ----------------------------
        $targets = (new UpdateTargets)->databases();
        $tenantCount = count($targets) - 1;

        $dbOk = false;
        $dbDetail = null;
        try {
            DB::connection('central')->select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbDetail = $e->getMessage();
        }
        $checks[] = $this->check('database', $this->t('database'), $dbOk, 'critical', $dbDetail);

        $probe = $this->probeTenants($targets, $tenantMigrations);
        $unreachable = $probe['unreachable'];
        $checks[] = $this->check('tenant_databases', $this->t('tenant_databases', ['count' => $tenantCount]), empty($unreachable), 'critical',
            empty($unreachable) ? null : $this->t('tenant_databases_fail', [
                'count' => count($unreachable),
                'tenants' => implode('; ', array_slice($unreachable, 0, 5)),
            ]));
        $package['databases'] = count($targets);

        // ---- backup plan + downtime estimate --------------------------------
        // Only tenants this release migrates are dumped: a tenant with no
        // pending migration keeps its tables, so there is nothing in it for
        // a rollback to restore (the seeders it still gets only add rows).
        $backupKeys = self::backupPlan($targets, $tenantMigrations, $probe['pending']);
        $package['backup_keys'] = $backupKeys;
        $package['databases_backed_up'] = count($backupKeys);
        $dumpedTenants = count($backupKeys) - 1;
        $dbBytes = array_sum(array_intersect_key($probe['sizes'], array_flip($backupKeys)));
        $checks[] = $this->check('backup_scope',
            match (true) {
                $dumpedTenants === 0 && $tenantCount > 0 => $this->t('backup_scope_none', ['total' => $tenantCount]),
                $dumpedTenants === $tenantCount => $this->t('backup_scope_all', ['total' => $tenantCount]),
                default => $this->t('backup_scope', ['dumped' => $dumpedTenants, 'total' => $tenantCount]),
            },
            true, 'warning', null);

        $estimate = self::estimateDowntime(
            count($backupKeys),
            $dbBytes,
            $tenantCount,
            array_sum(array_map('intval', array_filter($probe['pending'], fn ($p) => $p !== null)))
        );
        $package['estimate_seconds'] = $estimate;
        $checks[] = $this->check('downtime_estimate', $this->t('downtime_estimate', [
            'minutes' => max(1, (int) ceil($estimate / 60)),
            'dumps' => count($backupKeys),
            'size' => $this->fmtBytes((float) $dbBytes),
            'tenants' => $tenantCount,
        ]), true, 'warning', null);

        // ---- disk space ----------------------------------------------------
        // Needs: extracted copy (staging) + swap headroom + backup zip + one
        // dump per backed-up database (SQL text runs ~1.5x the table size).
        $zipSize = $package['size'];
        $needed = (int) (($package['uncompressed_size'] ?? $zipSize * 3) + $zipSize * 2 + $dbBytes * 1.5 + 200 * 1024 * 1024);
        $free = @disk_free_space(UpdaterPaths::storage('app'));
        $diskOk = $free === false ? true : $free > $needed;
        $checks[] = $this->check('disk_space', $this->t('disk_space', ['free' => $this->fmtBytes((float) $free), 'needed' => $this->fmtBytes($needed)]), $diskOk, 'critical',
            $diskOk ? null : $this->t('disk_space_fail'));

        // ---- configuration -------------------------------------------------
        $configOk = File::exists(base_path('.env')) && (string) config('app.key') !== '';
        $checks[] = $this->check('configuration', $this->t('configuration'), $configOk, 'critical',
            $configOk ? null : $this->t('configuration_fail'));

        // ---- maintenance mode ----------------------------------------------
        $maintenanceOk = is_writable(UpdaterPaths::storage('framework'));
        $checks[] = $this->check('maintenance_mode', $this->t('maintenance_mode'), $maintenanceOk, 'critical', null);

        // ---- concurrent update ---------------------------------------------
        $stateService = new UpdateState;
        $notRunning = ! $stateService->isActive()
            && ! File::exists(UpdaterPaths::storage('app/'.EnsureNotUpdating::LOCK_FILE));
        $checks[] = $this->check('no_update_running', $this->t('no_update_running'), $notRunning, 'critical',
            $notRunning ? null : $this->t('no_update_running_fail'));

        // ---- warnings (non-blocking) ---------------------------------------
        $checks[] = $this->check('exec_available', $this->t('exec_available'),
            function_exists('exec'), 'warning', null);

        $criticalFailed = array_values(array_filter($checks, fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));

        return [
            'ok' => empty($criticalFailed),
            'checks' => $checks,
            'package' => $package,
        ];
    }

    /**
     * Connect to every database once. Returns ['unreachable' => [labels],
     * 'sizes' => [key => bytes], 'pending' => [tenant key => number of the
     * package's tenant migrations that tenant has not run, null = unknown]].
     */
    private function probeTenants(array $targets, array $tenantMigrations): array
    {
        @set_time_limit(0);
        $result = ['unreachable' => [], 'sizes' => [], 'pending' => []];
        foreach ($targets as $target) {
            try {
                $info = UpdateTargets::run($target, function (string $connection) use ($target, $tenantMigrations) {
                    DB::connection($connection)->select('SELECT 1');
                    $pending = null;
                    if ($target['tenant'] !== null && $tenantMigrations) {
                        $ran = DB::connection($connection)->getSchemaBuilder()->hasTable('migrations')
                            ? DB::connection($connection)->table('migrations')->pluck('migration')->all()
                            : [];
                        $pending = count(array_diff($tenantMigrations, $ran));
                    }

                    return ['size' => (new DatabaseBackupService($connection))->databaseSize(), 'pending' => $pending];
                });
                $result['sizes'][$target['key']] = (int) $info['size'];
                if ($target['tenant'] !== null) {
                    $result['pending'][$target['key']] = $info['pending'];
                }
            } catch (\Throwable $e) {
                if ($target['tenant'] !== null) {
                    $result['unreachable'][] = $target['label'].' — '.mb_substr($e->getMessage(), 0, 160);
                    $result['pending'][$target['key']] = null;
                }
            }
        }

        return $result;
    }

    /**
     * Keys of the databases a run dumps: central always; a tenant only when
     * the package migrates it — or when that cannot be told (no tenant
     * migration list in the package, unreadable migrations table), in which
     * case it is backed up to be safe.
     */
    public static function backupPlan(array $targets, array $tenantMigrations, array $pending): array
    {
        $keys = [];
        foreach ($targets as $target) {
            if ($target['tenant'] === null || ! $tenantMigrations) {
                $keys[] = $target['key'];
                continue;
            }
            $p = $pending[$target['key']] ?? null;
            if ($p === null || $p > 0) {
                $keys[] = $target['key'];
            }
        }

        return $keys;
    }

    /**
     * Rough maintenance-window length in seconds, from what the sandbox runs
     * measured: mysqldump gets through ~4 MB/s of table size (data + index
     * as information_schema reports it) plus a few seconds per database,
     * each tenant takes ~10 s to migrate and re-seed (plus ~1 s per pending
     * migration), and the swap, central migration, caches and checks add
     * about 45 s. Slow disks and big tenants take longer.
     */
    public static function estimateDowntime(int $dumps, int $dumpBytes, int $tenants, int $pendingMigrations): int
    {
        return (int) round(45 + $dumps * 2 + $dumpBytes / (4 * 1024 * 1024) + $tenants * 10 + $pendingMigrations);
    }

    /** Laravel's migration name for a file: every ".php" stripped from the basename. */
    public static function migrationName(string $file): string
    {
        return str_replace('.php', '', basename($file));
    }

    public function currentVersion(): ?string
    {
        try {
            if (File::exists(base_path('version.txt'))) {
                return trim((string) File::get(base_path('version.txt')));
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** If every entry lives under one top-level folder, return "folder/". */
    public function detectRootPrefix(array $entries): ?string
    {
        $top = null;
        foreach ($entries as $name) {
            $slash = strpos($name, '/');
            $first = $slash === false ? null : substr($name, 0, $slash + 1);
            if ($first === null) {
                return null; // file at archive root — no prefix
            }
            if ($top === null) {
                $top = $first;
            } elseif ($top !== $first) {
                return null;
            }
        }

        return $top;
    }

    private function t(string $key, array $params = []): string
    {
        return __('super.system_update.checks.'.$key, $params);
    }

    private function check(string $key, string $label, bool $ok, string $level, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $level, 'detail' => $detail];
    }

    /** Collapse entries to their protected root (e.g. "public/images/") for a short summary. */
    private function topLevelOf(array $entries): array
    {
        $roots = [];
        foreach ($entries as $entry) {
            $entry = trim($entry, '/');
            foreach (UpdaterPaths::protectedPaths() as $protected) {
                if (strcasecmp($entry, $protected) === 0 || stripos($entry, $protected.'/') === 0) {
                    $roots[$protected] = true;
                    continue 2;
                }
            }
            $roots[$entry] = true; // nested .env
        }

        return array_slice(array_keys($roots), 0, 8);
    }

    private function fmtBytes(float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }
}
