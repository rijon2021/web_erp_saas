<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dump the central database to storage/app/backups/central.
 *
 * Strategy: use mysqldump when the PHP process may spawn it (exec() enabled
 * and the binary resolvable), otherwise — the usual case on shared hosting,
 * where exec() sits in disable_functions and no client binaries are on the
 * PATH — fall back to a pure-PHP dump written through the existing PDO
 * connection. Every failure path prints an "ERROR_DETAILS:" line that the
 * settings page surfaces verbatim, and the command never lets an exception
 * escape (an uncaught one turned into an opaque "Server Error" toast).
 */
class CentralDatabaseBackup extends Command
{
    protected $signature = 'central:backup';

    protected $description = 'Create a secured backup of the central database (retained, stored outside public).';

    /** Rows per SELECT chunk / multi-row INSERT in the PHP dumper. */
    private const CHUNK = 500;

    public function handle()
    {
        try {
            return $this->runBackup();
        } catch (\Throwable $e) {
            Log::error('central:backup failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->line('ERROR_DETAILS: ' . $e->getMessage());

            return 1;
        }
    }

    protected function runBackup(): int
    {
        $backupDir = storage_path('app/backups/central');
        if (! is_dir($backupDir) && ! @mkdir($backupDir, 0700, true) && ! is_dir($backupDir)) {
            $this->line('ERROR_DETAILS: Cannot create backup directory ' . $backupDir . '. Check that storage/ is writable.');

            return 1;
        }
        if (! is_writable($backupDir)) {
            $this->line('ERROR_DETAILS: Backup directory is not writable: ' . $backupDir);

            return 1;
        }

        // Protect the folder against direct web access even if the path is exposed
        $htaccess = $backupDir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        $indexHtml = $backupDir . '/index.html';
        if (! file_exists($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }

        $timestamp  = Carbon::now()->format('Y-m-d_His');
        $filename   = 'central-backup-' . $timestamp . '.sql';
        $outputPath = $backupDir . '/' . $filename;

        // The central DB is the tenancy central connection; fall back to the
        // app default when that name isn't configured.
        $conn = (string) config('tenancy.database.central_connection', config('database.default'));
        if (! config("database.connections.{$conn}")) {
            $conn = (string) config('database.default');
        }

        $method   = null;
        $problems = [];

        if ($this->canExec()) {
            $error = $this->dumpWithMysqldump($conn, $outputPath);
            if ($error === null) {
                $method = 'mysqldump';
            } else {
                $problems[] = 'mysqldump: ' . $error;
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
            }
        } else {
            $problems[] = 'mysqldump skipped: exec() is disabled by the host (disable_functions).';
        }

        if ($method === null) {
            // A big dump can exceed the default 30s web limit on slow hosts.
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $this->dumpWithPhp($conn, $outputPath);
            $method = 'php';
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            $this->line('ERROR_DETAILS: Backup file is empty or missing after the dump completed. ' . implode(' | ', $problems));

            return 1;
        }

        @chmod($outputPath, 0600);

        if ($problems) {
            Log::info('central:backup used the PHP dumper', ['reasons' => $problems]);
        }

        $this->line('BACKUP_METHOD: ' . $method);
        $this->line('BACKUP_FILE: ' . $filename);

        return 0;
    }

    // ──────────────────────────────────────────────
    //  mysqldump path
    // ──────────────────────────────────────────────

    protected function canExec(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    /** @return string|null Error description, or null on success. */
    protected function dumpWithMysqldump(string $conn, string $outputPath): ?string
    {
        $dumpPath = config("database.connections.{$conn}.dump_path")
            ?: config('database.connections.mysql.dump_path', 'mysqldump');
        $user     = (string) config("database.connections.{$conn}.username");
        $password = config("database.connections.{$conn}.password");
        $host     = (string) config("database.connections.{$conn}.host", '127.0.0.1');
        $port     = (string) config("database.connections.{$conn}.port", '3306');
        $database = (string) config("database.connections.{$conn}.database");

        $cmd  = escapeshellarg($dumpPath);
        $cmd .= ' --user=' . escapeshellarg($user);
        if ($password !== '' && $password !== null) {
            $cmd .= ' --password=' . escapeshellarg((string) $password);
        }
        $cmd .= ' --host=' . escapeshellarg($host);
        $cmd .= ' --port=' . escapeshellarg($port);
        $cmd .= ' --single-transaction --skip-lock-tables --routines --triggers';
        $cmd .= ' ' . escapeshellarg($database);
        $cmd .= ' > ' . escapeshellarg($outputPath);

        $output    = [];
        $returnVar = null;

        try {
            @exec($cmd . ' 2>&1', $output, $returnVar);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if ($returnVar !== 0) {
            $err = 'exit code ' . var_export($returnVar, true);
            if (! empty($output)) {
                $err .= ' — ' . trim(implode(' ', $output));
            } elseif (file_exists($outputPath) && filesize($outputPath) > 0) {
                $err .= ' — ' . trim((string) @file_get_contents($outputPath));
            }

            return $err;
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            return 'produced an empty file';
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Pure-PHP dump (no shell, no client binary)
    // ──────────────────────────────────────────────

    /**
     * Write a mysqldump-compatible SQL file through PDO: schema + data for
     * every base table, then views and triggers. Rows are streamed in
     * chunks so memory stays flat regardless of table size.
     */
    protected function dumpWithPhp(string $conn, string $outputPath): void
    {
        $connection = DB::connection($conn);
        $pdo        = $connection->getPdo();
        $database   = $connection->getDatabaseName();

        $fh = @fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open backup file for writing: ' . $outputPath);
        }

        try {
            $write = static function (string $sql) use ($fh, $outputPath): void {
                if (fwrite($fh, $sql) === false) {
                    throw new \RuntimeException('Write failed (disk full?) while dumping to ' . $outputPath);
                }
            };

            $write("-- Central database backup (PHP dumper)\n");
            $write('-- Database: ' . $database . "\n");
            $write('-- Generated: ' . Carbon::now()->toDateTimeString() . "\n\n");
            $write("SET NAMES utf8mb4;\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\n");
            $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            $write("SET time_zone='+00:00';\n\n");

            $tables = [];
            $views  = [];
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $row) {
                if (strtoupper((string) $row[1]) === 'VIEW') {
                    $views[] = (string) $row[0];
                } else {
                    $tables[] = (string) $row[0];
                }
            }

            foreach ($tables as $table) {
                $this->dumpTable($pdo, $table, $write);
            }

            foreach ($views as $view) {
                $create = $pdo->query('SHOW CREATE TABLE ' . $this->q($view))->fetch(\PDO::FETCH_ASSOC);
                $sql    = (string) ($create['Create View'] ?? '');
                if ($sql === '') {
                    continue;
                }
                $write("-- View: {$view}\n");
                $write('DROP VIEW IF EXISTS ' . $this->q($view) . ";\n");
                $write($this->stripDefiner($sql) . ";\n\n");
            }

            $this->dumpTriggers($pdo, $write);

            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }

    protected function dumpTable(\PDO $pdo, string $table, \Closure $write): void
    {
        $create = $pdo->query('SHOW CREATE TABLE ' . $this->q($table))->fetch(\PDO::FETCH_ASSOC);
        $sql    = (string) ($create['Create Table'] ?? '');
        if ($sql === '') {
            throw new \RuntimeException("SHOW CREATE TABLE returned nothing for {$table}.");
        }

        $write("-- Table: {$table}\n");
        $write('DROP TABLE IF EXISTS ' . $this->q($table) . ";\n");
        $write($sql . ";\n\n");

        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $this->q($table))->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            $columns[] = $this->q((string) $col['Field']);
        }
        if (! $columns) {
            return;
        }
        $columnList = implode(', ', $columns);

        $offset = 0;
        while (true) {
            $stmt = $pdo->query(sprintf(
                'SELECT * FROM %s LIMIT %d OFFSET %d',
                $this->q($table),
                self::CHUNK,
                $offset
            ));
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            $stmt->closeCursor();

            if (! $rows) {
                break;
            }

            $values = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $value) {
                    $cells[] = $this->literal($pdo, $value);
                }
                $values[] = '(' . implode(',', $cells) . ')';
            }

            $write('INSERT INTO ' . $this->q($table) . ' (' . $columnList . ") VALUES\n" . implode(",\n", $values) . ";\n");

            if (count($rows) < self::CHUNK) {
                break;
            }
            $offset += self::CHUNK;
        }

        $write("\n");
    }

    protected function dumpTriggers(\PDO $pdo, \Closure $write): void
    {
        try {
            $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return; // Missing TRIGGER privilege — a data backup is still valid.
        }

        foreach ($triggers as $trigger) {
            $name = (string) ($trigger['Trigger'] ?? '');
            if ($name === '') {
                continue;
            }
            try {
                $create = $pdo->query('SHOW CREATE TRIGGER ' . $this->q($name))->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                continue;
            }
            $sql = (string) ($create['SQL Original Statement'] ?? '');
            if ($sql === '') {
                continue;
            }
            $write("-- Trigger: {$name}\n");
            $write('DROP TRIGGER IF EXISTS ' . $this->q($name) . ";\n");
            $write("DELIMITER ;;\n" . $this->stripDefiner($sql) . ";;\nDELIMITER ;\n\n");
        }
    }

    /** SQL literal for one cell. */
    protected function literal(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        // Binary payloads (BLOBs, invalid UTF-8) travel safely as hex.
        if ($value !== '' && ! preg_match('//u', $value)) {
            return '0x' . bin2hex($value);
        }

        return $pdo->quote($value);
    }

    /** Backtick-quote an identifier. */
    protected function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Drop DEFINER clauses so the dump restores under any account (the
     * original definer usually doesn't exist on the target server).
     */
    protected function stripDefiner(string $sql): string
    {
        return (string) preg_replace('/\s*DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql);
    }
}
