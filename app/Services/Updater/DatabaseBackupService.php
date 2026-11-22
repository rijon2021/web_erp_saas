<?php

namespace App\Services\Updater;

use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Database dump and restore for the updater, bound to one connection:
 * 'central' for the platform database, 'tenant' (inside $tenant->run())
 * for a tenant database — so per-tenant MySQL users are honoured.
 *
 * Dump: tries the mysqldump binary first (fast, complete). When the binary
 * is unavailable (typical shared hosting) it falls back to a pure-PHP,
 * chunked dumper that can spread its work across multiple short HTTP
 * requests via a cursor persisted in the update state.
 *
 * Restore: tries the mysql binary, falling back to a chunked PHP executor
 * that replays the dump statement by statement from a byte offset.
 */
class DatabaseBackupService
{
    /** Rows per INSERT batch in the PHP dumper. */
    private const ROW_BATCH = 400;

    /** Why the last binary dump/restore attempt was not usable (for the run log). */
    public ?string $lastError = null;

    public function __construct(private string $connection = 'central')
    {
    }

    private function db()
    {
        return DB::connection($this->connection);
    }

    // ------------------------------------------------------------------ dump

    /**
     * Try to produce the full dump in one shot using mysqldump.
     * Returns true on success, false when the binary route is unusable.
     *
     * Delegates to the platform's hardened DatabaseDumper (the one behind
     * central/tenant backups): password in a private --defaults-file (never
     * in the process list, and a cPanel ~/.my.cnf cannot override a
     * tenant's own password), utf8mb4 so 4-byte characters survive on MySQL
     * 5.7, the connection's socket, and --result-file so stderr warnings can
     * never end up inside the dump and break its restore.
     */
    public function dumpWithBinary(string $outputPath): bool
    {
        $this->lastError = null;
        $dumper = new class extends DatabaseDumper
        {
            public function viaMysqldump(string $connection, string $outputPath): ?string
            {
                return $this->canExec() ? $this->dumpWithMysqldump($connection, $outputPath) : 'exec() is disabled';
            }
        };

        try {
            $error = $dumper->viaMysqldump($this->connection, $outputPath);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        clearstatcache(true, $outputPath);
        if ($error === null && (! File::exists($outputPath) || File::size($outputPath) < 100)) {
            $error = 'produced an empty dump';
        }
        if ($error !== null) {
            $this->lastError = $error;
            @File::delete($outputPath);

            return false;
        }

        return true;
    }

    /**
     * Chunked PHP dump. $cursor keeps ['tables'=>[], 'views'=>[], 'table_index'=>int,
     * 'row_offset'=>int, 'header_done'=>bool, 'done'=>bool]. Call repeatedly until
     * $cursor['done'] is true; $shouldStop() is consulted between batches.
     */
    public function dumpChunkPhp(string $outputPath, array &$cursor, callable $shouldStop): void
    {
        if (empty($cursor['header_done'])) {
            $tables = [];
            $views = [];
            foreach ($this->db()->select('SHOW FULL TABLES') as $row) {
                $vals = array_values((array) $row);
                if (($vals[1] ?? '') === 'VIEW') {
                    $views[] = $vals[0];
                } else {
                    $tables[] = $vals[0];
                }
            }
            $header = "-- Stocky updater PHP dump\n"
                ."-- Generated: ".date('Y-m-d H:i:s')."\n"
                ."SET NAMES utf8mb4;\n"
                ."SET FOREIGN_KEY_CHECKS=0;\n"
                ."SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
            File::put($outputPath, $header);
            $cursor = [
                'tool' => $cursor['tool'] ?? 'php',
                'tables' => $tables,
                'views' => $views,
                'table_index' => 0,
                'row_offset' => 0,
                'header_done' => true,
                'done' => false,
            ];
        }

        // A request that died after writing rows but before its cursor was
        // persisted leaves the file longer than the cursor knows about —
        // cut it back so resumed batches are never written twice.
        clearstatcache(true, $outputPath);
        if (isset($cursor['bytes']) && File::exists($outputPath) && File::size($outputPath) > $cursor['bytes']) {
            $fh = fopen($outputPath, 'r+b');
            if (! $fh || ! ftruncate($fh, (int) $cursor['bytes'])) {
                throw new \RuntimeException('Cannot rewind the database dump file to its last checkpoint');
            }
            fclose($fh);
        }

        $out = fopen($outputPath, 'ab');
        if (! $out) {
            throw new \RuntimeException('Cannot open database dump file for writing');
        }

        try {
            while ($cursor['table_index'] < count($cursor['tables'])) {
                $table = $cursor['tables'][$cursor['table_index']];

                if ($cursor['row_offset'] === 0) {
                    $create = $this->db()->select('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`');
                    $createSql = array_values((array) $create[0])[1] ?? null;
                    if (! $createSql) {
                        throw new \RuntimeException('Cannot read structure of table '.$table);
                    }
                    fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n");
                }

                $orderBy = $this->primaryKeyOrder($table);
                while (true) {
                    $rows = $this->db()->table($table)
                        ->when($orderBy, fn ($q) => $q->orderByRaw($orderBy))
                        ->offset($cursor['row_offset'])
                        ->limit(self::ROW_BATCH)
                        ->get();
                    if ($rows->isEmpty()) {
                        break;
                    }
                    fwrite($out, $this->insertStatement($table, $rows));
                    $cursor['row_offset'] += $rows->count();
                    if ($rows->count() < self::ROW_BATCH) {
                        break;
                    }
                    if ($shouldStop()) {
                        return; // resume later at this row offset
                    }
                }

                $cursor['table_index']++;
                $cursor['row_offset'] = 0;
                if ($shouldStop()) {
                    return;
                }
            }

            foreach ($cursor['views'] as $view) {
                $create = $this->db()->select('SHOW CREATE TABLE `'.str_replace('`', '``', $view).'`');
                $createSql = array_values((array) $create[0])[1] ?? null;
                if ($createSql) {
                    fwrite($out, "DROP VIEW IF EXISTS `{$view}`;\n{$createSql};\n\n");
                }
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n-- DUMP COMPLETE\n");
            $cursor['done'] = true;
        } finally {
            fclose($out);
            clearstatcache(true, $outputPath);
            $cursor['bytes'] = File::size($outputPath);
        }
    }

    /** Basic sanity check that a dump file exists and finished writing. */
    public function verifyDump(string $path, bool $phpDump): bool
    {
        if (! File::exists($path) || File::size($path) < 100) {
            return false;
        }
        if ($phpDump) {
            $tail = $this->tail($path, 200);

            return strpos($tail, '-- DUMP COMPLETE') !== false;
        }
        // mysqldump ends with "-- Dump completed" unless --skip-comments
        $tail = $this->tail($path, 400);

        return strpos($tail, 'Dump completed') !== false || strpos($tail, 'SET ') !== false;
    }

    // --------------------------------------------------------------- restore

    /**
     * Replay a dump with the mysql client. Same safeguards as the dump: the
     * password goes in a private --defaults-file (the only option file
     * read), and the session charset is utf8mb4.
     */
    public function restoreWithBinary(string $sqlPath): bool
    {
        $this->lastError = null;
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            $this->lastError = 'exec() is disabled';

            return false; // disable_functions on shared hosting
        }
        $db = $this->connectionConfig();
        $optionFile = $this->writeOptionFile(dirname($sqlPath), $db['password']);
        if ($optionFile === null) {
            $this->lastError = 'could not write a temporary MySQL option file';

            return false;
        }

        // --defaults-file must be the first option.
        $cmd = escapeshellarg($db['mysql_path'] ?: 'mysql')
            .' --defaults-file='.escapeshellarg($optionFile)
            .' --user='.escapeshellarg($db['username'])
            .' --host='.escapeshellarg($db['host'])
            .($db['port'] ? ' --port='.escapeshellarg((string) $db['port']) : '')
            .($db['socket'] !== '' ? ' --socket='.escapeshellarg($db['socket']) : '')
            .' --default-character-set=utf8mb4'
            .' '.escapeshellarg($db['database'])
            .' < '.escapeshellarg($sqlPath);

        $output = [];
        $ret = 1;
        try {
            @\exec($cmd.' 2>&1', $output, $ret);
        } catch (\Throwable $e) {
            $output = [$e->getMessage()];
        } finally {
            @unlink($optionFile);
        }
        if ($ret !== 0) {
            $this->lastError = 'exit code '.var_export($ret, true).($output ? ' — '.mb_substr(trim(implode(' ', $output)), 0, 300) : '');

            return false;
        }

        return true;
    }

    /** A private [client] option file holding the password, next to the dump. */
    private function writeOptionFile(string $dir, string $password): ?string
    {
        $path = $dir.DIRECTORY_SEPARATOR.'.mysql-'.bin2hex(random_bytes(8)).'.cnf.tmp';
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            return null;
        }
        @chmod($path, 0600);
        $content = "[client]\n";
        if ($password !== '') {
            $content .= 'password="'.strtr($password, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r'])."\"\n";
        }
        $ok = fwrite($fh, $content) === strlen($content);
        fclose($fh);
        if (! $ok) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * Names of the tables (and view stubs) a dump file creates — a linear
     * scan for CREATE TABLE statements.
     */
    public function dumpTableNames(string $sqlPath): array
    {
        $names = [];
        $handle = @fopen($sqlPath, 'rb');
        if (! $handle) {
            return $names;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^(?:\/\*M?!\d+\s*)?CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $line, $m)) {
                    $names[$m[1]] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        return array_keys($names);
    }

    /**
     * After a dump has been replayed, remove the base tables it does not
     * contain (typically tables created by the migrations of the failed
     * update). Views are left alone. Needed because tables are not wiped
     * before the replay — see UpdateManager::restoreDatabase().
     */
    public function dropTablesNotInDump(string $sqlPath): array
    {
        $keep = array_fill_keys($this->dumpTableNames($sqlPath), true);
        if (empty($keep)) {
            return []; // unreadable dump — never wipe on a guess
        }
        $dropped = [];
        $this->db()->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->db()->select('SHOW FULL TABLES') as $row) {
                $vals = array_values((array) $row);
                if (($vals[1] ?? '') === 'VIEW' || isset($keep[$vals[0]])) {
                    continue;
                }
                $this->db()->statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $vals[0]).'`');
                $dropped[] = $vals[0];
            }
        } finally {
            $this->db()->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $dropped;
    }

    /**
     * Chunked PHP restore: executes statements from $sqlPath starting at
     * $cursor['offset']. Handles mysqldump conditional comments and
     * DELIMITER blocks (triggers/routines). Sets $cursor['done'] when the
     * whole file has been replayed.
     *
     * Yielding ($shouldStop) is only honoured at a table boundary — right
     * before the next DROP TABLE/VIEW — so a table is always dropped and
     * refilled within one request. The super admin's next step request has
     * to authenticate against the central users table, which must therefore
     * never be left missing between requests.
     */
    public function restoreChunkPhp(string $sqlPath, array &$cursor, callable $shouldStop): void
    {
        $handle = fopen($sqlPath, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Cannot open SQL dump for restore');
        }
        try {
            fseek($handle, (int) ($cursor['offset'] ?? 0));
            $delimiter = $cursor['delimiter'] ?? ';';
            $buffer = '';

            $this->db()->statement('SET FOREIGN_KEY_CHECKS=0');

            while (($line = fgets($handle)) !== false) {
                $trim = trim($line);
                if ($buffer === '' && ($trim === '' || strpos($trim, '--') === 0)) {
                    $cursor['offset'] = ftell($handle);
                    continue;
                }
                if ($buffer === '' && stripos($trim, 'DELIMITER ') === 0) {
                    $delimiter = trim(substr($trim, 10));
                    $cursor['delimiter'] = $delimiter;
                    $cursor['offset'] = ftell($handle);
                    continue;
                }

                $buffer .= $line;
                $trimmedBuffer = rtrim($buffer);
                if (substr($trimmedBuffer, -strlen($delimiter)) === $delimiter) {
                    $statement = trim(substr($trimmedBuffer, 0, -strlen($delimiter)));
                    if ($statement !== '' && ! $this->isSessionRestoreStatement($statement)) {
                        $this->db()->unprepared($statement);
                    }
                    $buffer = '';
                    $cursor['offset'] = ftell($handle);
                    if ($shouldStop() && $this->atTableBoundary($handle)) {
                        return;
                    }
                }
            }

            // Trailing statement without terminator
            if (trim($buffer) !== '' && strpos(trim($buffer), '--') !== 0) {
                $this->db()->unprepared($buffer);
            }
            $cursor['offset'] = ftell($handle);
            $cursor['done'] = true;
            $this->db()->statement('SET FOREIGN_KEY_CHECKS=1');
        } finally {
            fclose($handle);
        }

        $cursor['dropped_extra'] = $this->dropTablesNotInDump($sqlPath);
    }

    /** Approximate on-disk size of the bound database (bytes), null when unreadable. */
    public function databaseSize(): ?int
    {
        try {
            $row = $this->db()->selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS size FROM information_schema.TABLES WHERE table_schema = ?',
                [$this->connectionConfig()['database']]
            );

            return (int) ($row->size ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * "SET <system var> = @<user var>" statements restore session settings
     * mysqldump saved earlier in the same file (@OLD_TIME_ZONE,
     * @saved_cs_client, ...). Replayed on a different connection the user
     * variable is NULL and MySQL refuses the assignment (error 1231) — and
     * a per-request connection is exactly what a chunked replay uses. They
     * only matter within a single client session, so they are skipped.
     */
    private function isSessionRestoreStatement(string $statement): bool
    {
        return (bool) preg_match('/^(?:\/\*M?!\d+\s*)?SET\s+(?:SESSION\s+)?[A-Za-z_]+\s*=\s*@[A-Za-z_]/i', $statement);
    }

    /**
     * Peek ahead (without consuming) — true when the next statement starts
     * a new table/view section or the file has ended.
     */
    private function atTableBoundary($handle): bool
    {
        $pos = ftell($handle);
        $boundary = true;
        while (($line = fgets($handle)) !== false) {
            $trim = trim($line);
            if ($trim === '' || strpos($trim, '--') === 0) {
                continue;
            }
            $boundary = (bool) preg_match('/^(?:\/\*M?!\d+\s*)?DROP\s+(?:TABLE|VIEW)\b/i', $trim);
            break;
        }
        fseek($handle, $pos);

        return $boundary;
    }

    // --------------------------------------------------------------- helpers

    private function connectionConfig(): array
    {
        $db = config('database.connections.'.$this->connection, []);
        // Tenant connections are built at runtime from the central template
        // and carry no dump_path — use the platform's.
        $dumpPath = (string) ($db['dump_path'] ?? config('database.connections.mysql.dump_path', ''));
        // mysql client: next to mysqldump when that is a full path.
        $mysqlPath = 'mysql';
        if ($dumpPath !== '' && (strpos($dumpPath, '/') !== false || strpos($dumpPath, '\\') !== false)) {
            $dir = dirname(trim($dumpPath, '"'));
            $candidate = $dir.DIRECTORY_SEPARATOR.(DIRECTORY_SEPARATOR === '\\' ? 'mysql.exe' : 'mysql');
            if (@is_file($candidate)) {
                $mysqlPath = $candidate;
            }
        }

        return [
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => $db['port'] ?? null,
            'database' => (string) ($db['database'] ?? ''),
            'socket' => (string) ($db['unix_socket'] ?? ''),
            'dump_path' => $dumpPath,
            'mysql_path' => $mysqlPath,
        ];
    }

    private function primaryKeyOrder(string $table): ?string
    {
        try {
            $keys = $this->db()->select("SHOW KEYS FROM `".str_replace('`', '``', $table)."` WHERE Key_name = 'PRIMARY'");
            if (empty($keys)) {
                return null;
            }
            $cols = array_map(fn ($k) => '`'.str_replace('`', '``', $k->Column_name).'`', $keys);

            return implode(', ', $cols);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function insertStatement(string $table, $rows): string
    {
        $pdo = $this->db()->getPdo();
        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ((array) $row as $value) {
                if ($value === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $vals[] = (string) $value;
                } else {
                    $vals[] = $pdo->quote((string) $value);
                }
            }
            $values[] = '('.implode(',', $vals).')';
        }
        if (empty($values)) {
            return '';
        }

        return "INSERT INTO `{$table}` VALUES\n".implode(",\n", $values).";\n";
    }

    private function tail(string $path, int $bytes): string
    {
        $size = File::size($path);
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return '';
        }
        fseek($handle, max(0, $size - $bytes));
        $tail = (string) fread($handle, $bytes);
        fclose($handle);

        return $tail;
    }
}
