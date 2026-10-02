<?php

namespace App\Services;

use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Explain why a platform update (/update) fails on the central database.
 *
 * The classic symptom is "SQLSTATE[42S01] ... Table 'x' already exists":
 * a table is present in the central DB but its creating migration has no
 * row in the `migrations` table, so `migrate` tries to create it again.
 *
 * This service collects everything needed to tell the possible causes
 * apart without shell access:
 *   - orphaned tables (exist, but their create-migration is not logged),
 *     with their CREATE_TIME and row count;
 *   - the shape of `sms_settings` (central vs tenant migration created it);
 *   - tenants whose database is the central database (tenant migrations
 *     would land in central);
 *   - the recent "Platform update" lines from laravel.log.
 *
 * Used by both `php artisan update:diagnose` and GET /update/diagnose.
 */
class UpdateDiagnostics
{
    /** Full report as an associative array. */
    public function report(): array
    {
        return [
            'central'   => $this->centralInfo(),
            'orphans'   => $this->orphanedTables(),
            'sms'       => $this->smsSettingsShape(),
            'tenants'   => $this->tenantsSharingCentralDb(),
            'batches'   => $this->recentBatches(),
            'log'       => $this->recentLogLines(),
        ];
    }

    /** Same report rendered as plain text (easy to read / paste). */
    public function toText(): string
    {
        $r   = $this->report();
        $out = [];

        $out[] = '== Central connection ==';
        foreach ($r['central'] as $k => $v) {
            $out[] = sprintf('%-14s %s', $k . ':', $v);
        }

        $out[] = '';
        $out[] = '== Orphaned tables (exist in central DB, creating migration NOT logged) ==';
        if (! $r['orphans']) {
            $out[] = 'none';
        }
        foreach ($r['orphans'] as $o) {
            $out[] = sprintf(
                '- %s  migration=%s  rows=%s  created=%s',
                $o['table'], $o['migration'], $o['rows'], $o['created_at'] ?? 'unknown'
            );
        }

        $out[] = '';
        $out[] = '== sms_settings shape ==';
        if (! $r['sms']['exists']) {
            $out[] = 'table does not exist';
        } else {
            $out[] = 'id type:          ' . $r['sms']['id_type'];
            $out[] = 'sms_gateway col:  ' . ($r['sms']['has_sms_gateway'] ? 'yes' : 'no');
            $out[] = 'rows:             ' . $r['sms']['rows'];
            $out[] = 'created:          ' . ($r['sms']['created_at'] ?? 'unknown');
            $out[] = 'migration logged: ' . ($r['sms']['migration_logged'] ? 'yes' : 'no');
            $out[] = 'verdict:          ' . $r['sms']['verdict'];
        }

        $out[] = '';
        $out[] = '== Tenants whose database is the central database ==';
        if (! $r['tenants']) {
            $out[] = 'none';
        }
        foreach ($r['tenants'] as $t) {
            $out[] = sprintf('- tenant %s (%s) -> db "%s" host %s', $t['id'], $t['domain'] ?: '-', $t['db'], $t['host']);
        }

        $out[] = '';
        $out[] = '== Last migration batches ==';
        foreach ($r['batches'] as $b) {
            $out[] = sprintf('- batch %d: %d migration(s), last: %s', $b['batch'], $b['count'], $b['last']);
        }

        $out[] = '';
        $out[] = '== Recent "Platform update" log lines ==';
        if (! $r['log']) {
            $out[] = 'none found in storage/logs';
        }
        foreach ($r['log'] as $line) {
            $out[] = $line;
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Insert `migrations` rows for orphaned tables so `migrate` skips them.
     * Returns the list of migrations that were marked.
     */
    public function markOrphansAsMigrated(): array
    {
        $conn   = DB::connection('central');
        $batch  = (int) $conn->table('migrations')->max('batch') + 1;
        $marked = [];

        foreach ($this->orphanedTables() as $o) {
            if (in_array($o['migration'], $marked, true)) {
                continue;
            }
            $conn->table('migrations')->insert([
                'migration' => $o['migration'],
                'batch'     => $batch,
            ]);
            $marked[] = $o['migration'];
        }

        return $marked;
    }

    // ──────────────────────────────────────────────────────────────────

    private function centralInfo(): array
    {
        $conn = DB::connection('central');

        return [
            'database'   => (string) $conn->getDatabaseName(),
            'host'       => (string) $conn->getConfig('host'),
            'default'    => (string) config('database.default'),
            'migrations' => (string) $conn->table('migrations')->count() . ' rows logged',
        ];
    }

    /**
     * Scan database/migrations/*.php for create('table') calls and report
     * tables that exist while their migration is not in the `migrations` table.
     */
    private function orphanedTables(): array
    {
        $conn   = DB::connection('central');
        $logged = $conn->table('migrations')->pluck('migration')->flip()->all();
        $out    = [];

        foreach (File::files(database_path('migrations')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getFilenameWithoutExtension();
            if (isset($logged[$name])) {
                continue;
            }
            $src = File::get($file->getPathname());
            if (! preg_match_all('/->create\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $src, $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $table) {
                if (! Schema::connection('central')->hasTable($table)) {
                    continue;
                }
                $out[] = [
                    'table'      => $table,
                    'migration'  => $name,
                    'rows'       => $this->safeCount($table),
                    'created_at' => $this->tableCreateTime($table),
                ];
            }
        }

        return $out;
    }

    private function smsSettingsShape(): array
    {
        $schema = Schema::connection('central');
        if (! $schema->hasTable('sms_settings')) {
            return ['exists' => false];
        }

        $idType = $this->columnType('sms_settings', 'id');
        $hasGw  = $schema->hasColumn('sms_settings', 'sms_gateway');
        $logged = DB::connection('central')->table('migrations')
            ->where('migration', '2026_07_06_100000_create_central_sms_settings_table')
            ->exists();

        if ($logged) {
            $verdict = 'Healthy: table tracked by the central migration. A failure here means two migrate runs overlapped (Re-run pressed while the previous run was still going).';
        } elseif ($hasGw) {
            $verdict = 'Created by the CENTRAL migration but the migrations row is gone: the migrations table was later replaced by a backup restore or a manual SQL import.';
        } else {
            $verdict = 'Created by the TENANT migration (no sms_gateway column, int id): tenant migrations were run against the central database (a tenant configured with the central DB name, or `migrate --path=database/migrations/tenant` run without a tenant).';
        }

        return [
            'exists'           => true,
            'id_type'          => $idType,
            'has_sms_gateway'  => $hasGw,
            'rows'             => $this->safeCount('sms_settings'),
            'created_at'       => $this->tableCreateTime('sms_settings'),
            'migration_logged' => $logged,
            'verdict'          => $verdict,
        ];
    }

    private function tenantsSharingCentralDb(): array
    {
        $central = strtolower((string) DB::connection('central')->getDatabaseName());
        $out     = [];

        foreach (Tenant::with('domains')->get() as $tenant) {
            try {
                $db = (string) $tenant->database()->getName();
            } catch (\Throwable $e) {
                $db = (string) ($tenant->tenancy_db_name ?? '');
            }
            if ($db !== '' && strtolower($db) === $central) {
                $out[] = [
                    'id'     => $tenant->id,
                    'domain' => optional($tenant->domains->first())->domain,
                    'db'     => $db,
                    'host'   => (string) ($tenant->tenancy_db_host ?? config('database.connections.central.host')),
                ];
            }
        }

        return $out;
    }

    private function recentBatches(): array
    {
        return DB::connection('central')->table('migrations')
            ->selectRaw('batch, COUNT(*) as cnt, MAX(migration) as last')
            ->groupBy('batch')
            ->orderByDesc('batch')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['batch' => (int) $r->batch, 'count' => (int) $r->cnt, 'last' => (string) $r->last])
            ->all();
    }

    private function recentLogLines(int $limit = 40): array
    {
        $lines = [];
        $files = glob(storage_path('logs/*.log')) ?: [];
        rsort($files);

        foreach (array_slice($files, 0, 3) as $path) {
            $fh = @fopen($path, 'r');
            if (! $fh) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                if (stripos($line, 'Platform update') !== false || stripos($line, 'sms_settings') !== false) {
                    $lines[] = rtrim(substr($line, 0, 400));
                }
            }
            fclose($fh);
        }

        return array_slice($lines, -$limit);
    }

    private function columnType(string $table, string $column): string
    {
        try {
            $row = DB::connection('central')->selectOne("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);

            return (string) ($row->Type ?? '?');
        } catch (\Throwable $e) {
            return '?';
        }
    }

    private function safeCount(string $table): string
    {
        try {
            return (string) DB::connection('central')->table($table)->count();
        } catch (\Throwable $e) {
            return '?';
        }
    }

    private function tableCreateTime(string $table): ?string
    {
        try {
            $row = DB::connection('central')->selectOne(
                'SELECT CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );

            return $row->CREATE_TIME ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
