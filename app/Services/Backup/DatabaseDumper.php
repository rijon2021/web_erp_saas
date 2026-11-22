<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dump one database connection to a .sql file (or .sql.gz).
 *
 * Strategy: use mysqldump when the PHP process may spawn it (exec() enabled
 * and the binary resolvable), otherwise — the usual case on shared hosting,
 * where exec() sits in disable_functions and no client binaries are on the
 * PATH — fall back to a pure-PHP dump written through the existing PDO
 * connection.
 *
 * Shared by the central backup (central:backup), the tenant's on-demand backup
 * (database:backup) and the plan-scheduled tenant backups (tenants:backup).
 * For a tenant, pass the `tenant` connection while tenancy is initialised: the
 * bootstrapper has already pointed it at that tenant's own credentials.
 */
class DatabaseDumper
{
    /** Rows per SELECT chunk / multi-row INSERT in the PHP dumper. */
    private const CHUNK = 500;

    /** Largest multi-row INSERT the PHP dumper writes (a single bigger row still gets its own). */
    private const MAX_INSERT_BYTES = 1048576;

    /** Problems met on the way to a successful dump (e.g. why mysqldump was skipped). */
    public array $problems = [];

    /**
     * @return string The method that produced the file: 'mysqldump' or 'php'.
     *
     * @throws \RuntimeException When no method produced a non-empty file.
     */
    public function dump(string $conn, string $outputPath, string $label = 'Database'): string
    {
        $this->problems = [];
        $method = null;

        // Written under a temporary name and renamed into place only once it
        // is complete, so a dump that dies half-way (OOM, kill, reboot) never
        // sits in the folder looking like a good backup.
        $partPath = $outputPath . '.part';
        @unlink($partPath);

        try {
            if ($this->canExec()) {
                $error = $this->dumpWithMysqldump($conn, $partPath);
                if ($error === null) {
                    $method = 'mysqldump';
                } else {
                    $this->problems[] = 'mysqldump: ' . $error;
                    @unlink($partPath);
                }
            } else {
                $this->problems[] = 'mysqldump skipped: exec() is disabled by the host (disable_functions).';
            }

            if ($method === null) {
                // A big dump can exceed the default 30s web limit on slow hosts.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(0);
                }
                $this->dumpWithPhp($conn, $partPath, $label);
                $method = 'php';
            }

            clearstatcache(true, $partPath);
            if (! file_exists($partPath) || filesize($partPath) === 0) {
                throw new \RuntimeException('Backup file is empty or missing after the dump completed. ' . implode(' | ', $this->problems));
            }
            if (! @rename($partPath, $outputPath)) {
                throw new \RuntimeException('Could not move the finished backup into place: ' . $outputPath);
            }
        } finally {
            @unlink($partPath);
        }

        return $method;
    }

    /**
     * Dump, then gzip to "$outputPath.gz" — a SQL dump compresses roughly
     * 5-10x, which is what makes keeping several scheduled copies per tenant
     * affordable. Neither the plain dump nor the half-written archive ever
     * carries the final name.
     *
     * @return string The .gz path.
     */
    public function dumpCompressed(string $conn, string $outputPath, string $label = 'Database'): string
    {
        $plainPath = $outputPath . '.tmp';   // not a backup name (see TenantBackupStore)
        $gzPart = $outputPath . '.gz.part';
        $gzPath = $outputPath . '.gz';

        try {
            $this->dump($conn, $plainPath, $label);

            $in = @fopen($plainPath, 'rb');
            $out = $in === false ? false : @gzopen($gzPart, 'wb6');
            if ($in === false || $out === false) {
                if ($in !== false) {
                    fclose($in);
                }
                throw new \RuntimeException('Cannot open files to compress ' . $plainPath);
            }
            try {
                while (! feof($in)) {
                    $chunk = fread($in, 1024 * 512);
                    if ($chunk === false) {
                        throw new \RuntimeException('Read failed while compressing ' . $plainPath);
                    }
                    $written = gzwrite($out, $chunk);
                    if ($written === false || ($chunk !== '' && $written === 0)) {
                        throw new \RuntimeException('Compression failed (disk full?) for ' . $plainPath);
                    }
                }
            } finally {
                fclose($in);
                gzclose($out);
            }

            // gzclose() reports success even when flushing the last block
            // failed, so read the archive back: it must decompress to exactly
            // the dump's size, or it is truncated/corrupt and is not kept.
            $expected = filesize($plainPath);
            $actual = $this->decompressedSize($gzPart);
            if ($actual !== $expected) {
                throw new \RuntimeException("Compressed backup failed verification ({$actual} of {$expected} bytes; disk full?)");
            }

            if (! @rename($gzPart, $gzPath)) {
                throw new \RuntimeException('Could not move the finished backup into place: ' . $gzPath);
            }
        } finally {
            @unlink($plainPath);
            @unlink($gzPart);
        }

        return $gzPath;
    }

    /** Bytes a gzip file decompresses to, or -1 if it can't be read to the end. */
    private function decompressedSize(string $gzPath): int
    {
        $gz = @gzopen($gzPath, 'rb');
        if ($gz === false) {
            return -1;
        }
        $total = 0;
        try {
            while (! gzeof($gz)) {
                $chunk = gzread($gz, 1024 * 1024);
                if ($chunk === false) {
                    return -1;
                }
                if ($chunk === '' && ! gzeof($gz)) {
                    return -1; // truncated stream: no data, no end
                }
                $total += strlen($chunk);
            }
        } finally {
            gzclose($gz);
        }

        return $total;
    }

    /**
     * Make a backup directory, plus deny files in case the path ever ends up
     * under a web root. Permissions follow the process umask like the rest of
     * storage/.
     *
     * The scheduler and the web server can run as different users. When the
     * scheduler runs as root, the new folders are handed to the owner of
     * storage/ — otherwise a root-owned backups/ folder would stop the web
     * user creating manual/ next to it, and "Generate backup" would fail.
     */
    public static function ensurePrivateDir(string $dir): void
    {
        if (! is_dir($dir)) {
            $missing = [];
            for ($d = $dir; $d !== '' && ! is_dir($d) && dirname($d) !== $d; $d = dirname($d)) {
                $missing[] = $d;
            }
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Cannot create backup directory ' . $dir . '. Check that storage/ is writable.');
            }
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $owner = @fileowner(storage_path());
                $group = @filegroup(storage_path());
                foreach ($missing as $created) {
                    if ($owner !== false) {
                        @chown($created, $owner);
                    }
                    if ($group !== false) {
                        @chgrp($created, $group);
                    }
                }
            }
        }
        if (! is_writable($dir)) {
            throw new \RuntimeException('Backup directory is not writable: ' . $dir);
        }
        if (! file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        if (! file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
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

        // The password goes in a private option file, not on the command line:
        // a --password argument is readable by every user via the process list,
        // and makes MySQL print an "insecure" warning on every run. It is a
        // --defaults-file (the ONLY option file read), not an extra one: hosts
        // like cPanel keep a ~/.my.cnf with the account's own password, which
        // is read after an extra file and would replace the tenant's password.
        $optionFile = $this->writeOptionFile(dirname($outputPath), (string) ($password ?? ''));
        if ($optionFile === null) {
            return 'could not write a temporary MySQL option file in ' . dirname($outputPath);
        }

        // --defaults-file must be the first option. The dump is written by
        // --result-file, so the 2>&1 below captures only messages: with a
        // "> file" redirect, stderr warnings landed inside the backup itself
        // and broke its restore.
        $cmd = escapeshellarg($dumpPath);
        $cmd .= ' --defaults-file=' . escapeshellarg($optionFile);
        $cmd .= ' --user=' . escapeshellarg($user);
        $cmd .= ' --host=' . escapeshellarg($host);
        $cmd .= ' --port=' . escapeshellarg($port);
        // With only our option file read, a socket path set in /etc/my.cnf is
        // not picked up — pass the connection's own when it has one.
        $socket = (string) config("database.connections.{$conn}.unix_socket", '');
        if ($socket !== '') {
            $cmd .= ' --socket=' . escapeshellarg($socket);
        }
        $cmd .= ' --single-transaction --skip-lock-tables --routines --triggers';
        // Tablespace info needs the global PROCESS privilege (MySQL 8.0.21+),
        // which a tenant's own database user does not have; InnoDB per-table
        // dumps never needed it.
        $cmd .= ' --no-tablespaces';
        // MySQL 5.7's mysqldump defaults to 3-byte utf8: emoji and other
        // 4-byte characters were written as '?' and lost on restore.
        $cmd .= ' --default-character-set=utf8mb4';
        $cmd .= ' --result-file=' . escapeshellarg($outputPath);
        $cmd .= ' ' . escapeshellarg($database);

        $output    = [];
        $returnVar = null;

        try {
            @exec($cmd . ' 2>&1', $output, $returnVar);
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            @unlink($optionFile);
        }

        if ($returnVar !== 0) {
            // Only mysqldump's own messages — never the (partial) dump file,
            // which holds tenant data and can be huge.
            return 'exit code ' . var_export($returnVar, true)
                . ($output ? ' — ' . mb_substr(trim(implode(' ', $output)), 0, 1000) : '');
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            return 'produced an empty file';
        }

        return null;
    }

    /**
     * A [client] option file holding the password, created next to the backup
     * (private storage — not /tmp, which open_basedir may forbid and where a
     * killed run would leave it world-listable) and readable only by this
     * user. Named *.tmp so a leftover from a killed run is swept by
     * TenantBackupStore's stale-file cleanup. Null if it can't be written.
     */
    private function writeOptionFile(string $dir, string $password): ?string
    {
        $path = $dir . '/.mysqldump-' . bin2hex(random_bytes(8)) . '.cnf.tmp';

        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            return null;
        }
        @chmod($path, 0600);

        $content = "[client]\n";
        if ($password !== '') {
            // Double-quoted value: backslash escapes are decoded, so escape the
            // backslash itself, the quote, and line breaks (\n, \r).
            $content .= 'password="' . strtr($password, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']) . "\"\n";
        }

        $ok = fwrite($fh, $content) === strlen($content);
        fclose($fh);
        if (! $ok) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    // ──────────────────────────────────────────────
    //  Pure-PHP dump (no shell, no client binary)
    // ──────────────────────────────────────────────

    /**
     * Write a mysqldump-compatible SQL file through PDO: schema + data for
     * every base table, then views and triggers. Rows are streamed in
     * chunks so memory stays flat regardless of table size.
     */
    protected function dumpWithPhp(string $conn, string $outputPath, string $label): void
    {
        $connection = DB::connection($conn);
        $pdo        = $connection->getPdo();
        $database   = $connection->getDatabaseName();

        $fh = @fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open backup file for writing: ' . $outputPath);
        }

        // The file declares time_zone '+00:00', so TIMESTAMP values must be read
        // in UTC too — read in the session's zone they shift on restore. This
        // is a shared connection: the zone is put back afterwards.
        $previousTz = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $pdo->exec("SET time_zone = '+00:00'");

        // One consistent snapshot for every table, as mysqldump's
        // --single-transaction gives — otherwise rows written mid-dump land in
        // some tables and not others. Not when the caller is already inside a
        // transaction: START TRANSACTION would silently commit it.
        $ownSnapshot = $connection->transactionLevel() === 0 && ! $pdo->inTransaction();
        if ($ownSnapshot) {
            // The snapshot only holds under REPEATABLE READ (some managed MySQL
            // services default to READ COMMITTED). Without SESSION this applies
            // to the next transaction only, as mysqldump does.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        try {
            $write = static function (string $sql) use ($fh, $outputPath): void {
                if (fwrite($fh, $sql) === false) {
                    throw new \RuntimeException('Write failed (disk full?) while dumping to ' . $outputPath);
                }
            };

            $write("-- {$label} backup (PHP dumper)\n");
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
            // Best effort: after a lost connection these throw too, and must not
            // replace the error that actually stopped the dump.
            try {
                if ($ownSnapshot) {
                    $pdo->exec('COMMIT');
                }
                $pdo->exec('SET time_zone = ' . $pdo->quote($previousTz));
            } catch (\Throwable) {
            }
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

        $fields = [];
        $types = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $this->q($table))->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            $fields[] = (string) $col['Field'];
            $types[(string) $col['Field']] = strtolower((string) $col['Type']);
        }
        if (! $fields) {
            return;
        }
        $columnList = implode(', ', array_map(fn ($f) => $this->q($f), $fields));

        $primary = [];
        foreach ($pdo->query('SHOW KEYS FROM ' . $this->q($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC) as $key) {
            $primary[(int) $key['Seq_in_index']] = (string) $key['Column_name'];
        }
        ksort($primary);
        $primary = array_values($primary);

        // Single-column primary key: page by key (WHERE pk > last), which stays
        // fast deep into big tables. Otherwise OFFSET over a stable order.
        $keyIndex = count($primary) === 1 ? array_search($primary[0], $fields, true) : false;
        $orderBy = $primary ? ' ORDER BY ' . implode(', ', array_map(fn ($c) => $this->q($c), $primary)) : '';
        $lastKey = null;
        $offset = 0;

        $insertHead = 'INSERT INTO ' . $this->q($table) . ' (' . $columnList . ") VALUES\n";

        while (true) {
            if ($keyIndex !== false) {
                $sql = 'SELECT * FROM ' . $this->q($table)
                    . ($lastKey === null ? '' : ' WHERE ' . $this->q($primary[0]) . ' > ' . $this->keyLiteral($pdo, $lastKey, $types[$primary[0]] ?? ''))
                    . $orderBy . ' LIMIT ' . self::CHUNK;
            } else {
                $sql = sprintf('SELECT * FROM %s%s LIMIT %d OFFSET %d', $this->q($table), $orderBy, self::CHUNK, $offset);
            }
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            $stmt->closeCursor();

            if (! $rows) {
                break;
            }
            if ($keyIndex !== false) {
                $lastKey = $rows[count($rows) - 1][$keyIndex];
            }

            // Rows are grouped into INSERTs of at most ~1 MB, like mysqldump's
            // net_buffer_length: 500 rows of large log/payload text in one
            // statement overran max_allowed_packet (4 MB by default on 5.7)
            // and the backup could not be restored.
            $values = [];
            $bytes = 0;
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $value) {
                    $cells[] = $this->literal($pdo, $value);
                }
                $tuple = '(' . implode(',', $cells) . ')';

                if ($values && $bytes + strlen($tuple) > self::MAX_INSERT_BYTES) {
                    $write($insertHead . implode(",\n", $values) . ";\n");
                    $values = [];
                    $bytes = 0;
                }
                $values[] = $tuple;
                $bytes += strlen($tuple) + 2;
            }

            $write($insertHead . implode(",\n", $values) . ";\n");

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

    /**
     * The last-seen key for "WHERE pk > ?". Integers unquoted (a quoted big
     * integer is compared as a double and can skip rows), binary keys as hex
     * (non-UTF-8 bytes in a quoted literal are mangled), the rest quoted.
     */
    private function keyLiteral(\PDO $pdo, mixed $value, string $type): string
    {
        if (str_contains($type, 'int') && preg_match('/^-?\d+$/', (string) $value)) {
            return (string) $value;
        }
        if (str_contains($type, 'binary') || str_contains($type, 'blob')) {
            return '0x' . bin2hex((string) $value);
        }

        return $pdo->quote((string) $value);
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
