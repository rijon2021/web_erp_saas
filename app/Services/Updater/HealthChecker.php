<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Post-update health verification. "Update Successful" is only shown after
 * these pass. Critical failures trigger the automatic rollback.
 */
class HealthChecker
{
    /**
     * @param  array  $targets  the run's database targets (central + tenants)
     */
    public function verify(?string $expectedVersion, array $targets = []): array
    {
        $checks = [];

        // Application files
        $missing = [];
        foreach (array_merge(PackageValidator::REQUIRED_ENTRIES, ['routes/central.php', 'routes/tenant_api.php']) as $file) {
            if (! File::exists(base_path(str_replace('/', DIRECTORY_SEPARATOR, $file)))) {
                $missing[] = $file;
            }
        }
        $checks[] = $this->check('required_files', $this->t('required_files'), empty($missing), 'critical',
            empty($missing) ? null : $this->t('required_files_fail', ['files' => implode(', ', $missing)]));

        // Version
        $version = null;
        try {
            $version = trim((string) File::get(base_path('version.txt')));
        } catch (\Throwable $e) {
        }
        $versionOk = $expectedVersion === null || $version === trim($expectedVersion);
        $checks[] = $this->check('version', $this->t('version', ['version' => $version ?: '?']), $versionOk, 'critical',
            $versionOk ? null : $this->t('version_fail', ['expected' => $expectedVersion, 'found' => $version ?: '—']));

        // Central database
        $dbOk = false;
        $dbDetail = null;
        try {
            $schema = DB::connection('central')->getSchemaBuilder();
            $dbOk = $schema->hasTable('tenants') && $schema->hasTable('domains');
            if (! $dbOk) {
                $dbDetail = $this->t('database_fail');
            }
        } catch (\Throwable $e) {
            $dbDetail = $e->getMessage();
        }
        $checks[] = $this->check('database', $this->t('database'), $dbOk, 'critical', $dbDetail);

        // Pending migrations — central, then every tenant of the run
        $centralFiles = $this->migrationNames(database_path('migrations'));
        $tenantFiles = $this->migrationNames(database_path('migrations'.DIRECTORY_SEPARATOR.'tenant'));
        $pendingCentral = null;
        try {
            $ran = DB::connection('central')->table('migrations')->pluck('migration')->all();
            $pendingCentral = count(array_diff($centralFiles, $ran));
        } catch (\Throwable $e) {
        }
        $checks[] = $this->check('migrations', $this->t('migrations'), $pendingCentral === 0, 'critical',
            $pendingCentral === 0 ? null : (is_int($pendingCentral) ? $this->t('migrations_pending', ['count' => $pendingCentral]) : $this->t('migrations_unreadable')));

        $tenantProblems = [];
        $tenantTotal = 0;
        foreach ($targets as $target) {
            if (($target['tenant'] ?? null) === null) {
                continue;
            }
            $tenantTotal++;
            try {
                $pending = UpdateTargets::run($target, function (string $connection) use ($tenantFiles) {
                    $ran = DB::connection($connection)->table('migrations')->pluck('migration')->all();

                    return count(array_diff($tenantFiles, $ran));
                });
                if ($pending > 0) {
                    $tenantProblems[] = $target['label'].' ('.$pending.')';
                }
            } catch (\Throwable $e) {
                $tenantProblems[] = $target['label'].' — '.mb_substr($e->getMessage(), 0, 120);
            }
        }
        $checks[] = $this->check('tenant_migrations', $this->t('tenant_migrations', ['count' => $tenantTotal]), empty($tenantProblems), 'critical',
            empty($tenantProblems) ? null : $this->t('tenant_migrations_fail', ['tenants' => implode('; ', array_slice($tenantProblems, 0, 5))]));

        // Configuration
        $envOk = File::exists(base_path('.env'));
        $keyOk = false;
        try {
            $env = (string) File::get(base_path('.env'));
            $keyOk = (bool) preg_match('/^APP_KEY=.+\S/m', $env);
        } catch (\Throwable $e) {
        }
        $checks[] = $this->check('configuration', $this->t('configuration'), $envOk && $keyOk, 'critical', null);

        // Storage
        $storageOk = is_writable(UpdaterPaths::storage()) && is_writable(UpdaterPaths::storage('framework'))
            && is_writable(base_path('bootstrap/cache'));
        $checks[] = $this->check('storage', $this->t('storage'), $storageOk, 'critical', null);

        // New autoloader actually resolves classes shipped by the package
        $autoloadOk = File::exists(base_path('vendor/composer/autoload_real.php'));
        $checks[] = $this->check('autoload', $this->t('autoload'), $autoloadOk, 'critical', null);

        // Loopback HTTP on the host serving this request (best effort — some
        // hosts cannot reach themselves)
        $loopback = null;
        try {
            $host = request() ? request()->getSchemeAndHttpHost() : '';
            if ($host !== '') {
                $ctx = stream_context_create([
                    'http' => ['timeout' => 8, 'ignore_errors' => true],
                    'https' => ['timeout' => 8, 'ignore_errors' => true],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $body = @file_get_contents($host.'/super/system-update/ping', false, $ctx);
                $loopback = is_string($body) && strpos($body, 'pong') !== false;
            }
        } catch (\Throwable $e) {
            $loopback = false;
        }
        if ($loopback !== null) {
            $checks[] = $this->check('http', $this->t('http'), $loopback, 'warning',
                $loopback ? null : $this->t('http_fail'));
        }

        $criticalFailed = array_values(array_filter($checks, fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));

        return ['ok' => empty($criticalFailed), 'checks' => $checks];
    }

    /**
     * Migration names in one directory (not recursive), mirroring Laravel's
     * naming exactly: it strips EVERY ".php" from the basename.
     */
    private function migrationNames(string $dir): array
    {
        return collect(File::glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [])
            ->map(fn ($f) => str_replace('.php', '', basename($f)))
            ->all();
    }

    private function t(string $key, array $params = []): string
    {
        return __('super.system_update.health.'.$key, $params);
    }

    private function check(string $key, string $label, bool $ok, string $level, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $level, 'detail' => $detail];
    }
}
