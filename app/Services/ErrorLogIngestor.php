<?php

namespace App\Services;

use App\Models\ErrorLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies ERROR-and-worse entries from storage/logs/*.log into error_logs, so
 * the Error Logs report shows real application failures — not only the few
 * places that call ErrorLog::create() themselves.
 *
 * Runs when the report is opened (no cron, nothing on the request hot path)
 * and reads each file incrementally from a saved byte offset, so every entry
 * is imported exactly once. Errors that happened while the database was down
 * (which could never have been written at the time) arrive on the next run.
 *
 * Multi-tenant: every tenant writes to the same central log file, so each
 * record is tagged with the tenant id (App\Listeners\TagLogsWithTenant puts
 * it in the shared log context) and a tenant imports ONLY its own records.
 * Untagged records (central / pre-tenancy errors) are never shown to anyone.
 * The read offsets live in the tenant's own storage (storage_path() is
 * tenant-suffixed), so each tenant keeps its own position in the shared file.
 */
class ErrorLogIngestor
{
    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** Bytes read per file per run; a backlog finishes over a few page loads. */
    private const CHUNK = 5 * 1024 * 1024;

    private const MAX_TRACE_LINES = 60;

    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] [\w-]+\.([A-Z]+): /m';

    private ?string $tenantId = null;

    public function run(): int
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        if (! $tenant || ! Schema::hasTable('error_logs')) {
            return 0;
        }
        $this->tenantId = (string) $tenant->getTenantKey();

        // Two tabs opening the report at once must not import twice. The
        // tenant id is in the name so tenants never wait on each other,
        // whatever the cache driver does with prefixes.
        $lock = Cache::lock('error-log-ingest-'.$this->tenantId, 60);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $state = $this->loadState();
            $imported = 0;
            $seen = [];

            foreach (glob($this->logDirectory().'/*.log') ?: [] as $path) {
                $name = basename($path);
                $seen[] = $name;
                $imported += $this->ingestFile($path, $state[$name] ?? null, $offset);
                $state[$name] = $offset;
            }

            // Forget rotated-away files.
            $state = array_intersect_key($state, array_flip($seen));
            $this->saveState($state);

            return $imported;
        } catch (\Throwable $e) {
            return 0;
        } finally {
            $lock->release();
        }
    }

    private function ingestFile(string $path, ?int $offset, ?int &$newOffset): int
    {
        clearstatcache(true, $path);
        $size = (int) @filesize($path);

        // First sight of a huge file: only its recent tail is worth importing.
        if ($offset === null) {
            $offset = max(0, $size - self::CHUNK);
        }
        // Truncated / rotated in place: start over.
        if ($offset > $size) {
            $offset = 0;
        }
        $newOffset = $offset;
        if ($offset === $size) {
            return 0;
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return 0;
        }
        fseek($handle, $offset);
        $chunk = (string) fread($handle, self::CHUNK);
        fclose($handle);

        $atEof = $offset + strlen($chunk) >= $size;

        if (! preg_match_all(self::HEADER, $chunk, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            // No entry header at all (mid-entry tail of a giant record): skip it.
            $newOffset = $offset + strlen($chunk);

            return 0;
        }

        $imported = 0;
        $count = count($m);
        for ($i = 0; $i < $count; $i++) {
            $start = $m[$i][0][1];
            $end = $i + 1 < $count ? $m[$i + 1][0][1] : strlen($chunk);
            $isLast = $i + 1 === $count;

            // The last entry may still be mid-write (or cut by the chunk
            // size): leave it for the next run unless it is complete.
            if ($isLast && ! ($atEof && str_ends_with($chunk, "\n")) && $count > 1) {
                $newOffset = $offset + $start;

                return $imported;
            }

            $level = $m[$i][2][0];
            if (in_array($level, self::LEVELS, true)) {
                $imported += $this->store($m[$i][1][0], $level, substr($chunk, $start + strlen($m[$i][0][0]), $end - $start - strlen($m[$i][0][0])));
            }
        }

        $newOffset = $offset + strlen($chunk);

        return $imported;
    }

    /** One log record → one error_logs row (unless the app already logged it). */
    private function store(string $time, string $level, string $body): int
    {
        $body = mb_scrub(rtrim($body), 'UTF-8');
        if ($this->recordTenant($body) !== $this->tenantId) {
            return 0; // another tenant's (or a central) error
        }
        $firstLine = strtok($body, "\n") ?: '';

        $exception = null;
        $code = null;
        $file = null;
        $line = null;
        $userId = null;
        $context = null;
        $message = $firstLine;

        // Exceptions: `message {"userId":1,"exception":"[object] (Class(code: X): text at file:line)`
        // — other context keys (userId…) may come first, and the message
        // itself may span several lines.
        $marker = strpos($body, '"exception":"[object] (');
        if ($marker !== false) {
            $brace = strrpos(substr($body, 0, $marker), ' {');
            $message = $brace !== false ? substr($body, 0, $brace) : $firstLine;
            $prefix = $brace !== false ? substr($body, $brace, $marker - $brace) : '';
            if (preg_match('/"userId":"?(\d+)/', $prefix, $um)) {
                $userId = (int) $um[1];
            }

            $stack = strpos($body, "\n[stacktrace]", $marker);
            $header = rtrim(substr($body, $marker, $stack !== false ? $stack - $marker : null));
            if (preg_match('/^"exception":"\[object\] \(([^(]+)\(code: ([^)]*)\): (.*) at (.+):(\d+)\)$/s', $header, $em)) {
                $exception = stripslashes($em[1]);
                $code = $em[2];
                $file = stripslashes($em[4]);
                $line = (int) $em[5];
            }
        } elseif (preg_match('/^(.*?) (\{.*\}|\[\])(?: \[\])?$/s', $firstLine, $cm)) {
            // Plain Log::error('text', [context]) — keep the context separately.
            $message = $cm[1];
            $context = $cm[2] !== '[]' ? $cm[2] : null;
        }

        $trace = null;
        if (($pos = strpos($body, "[stacktrace]\n")) !== false) {
            $lines = explode("\n", substr($body, $pos + 13));
            $lines = array_filter($lines, fn ($l) => str_starts_with($l, '#'));
            $trace = stripslashes(implode("\n", array_slice($lines, 0, self::MAX_TRACE_LINES)));
        }

        $occurredAt = str_replace('T', ' ', $time);
        $message = trim($message) !== '' ? $message : $firstLine;

        if ($this->alreadyLogged($occurredAt, $message)) {
            return 0;
        }

        $details = array_filter([
            'source' => 'log file',
            'level' => $level,
            'exception' => $exception,
            'code' => $code !== '0' ? $code : null,
            'file' => $file,
            'line' => $line,
            'user_id' => $userId,
            'context' => $context,
            'trace' => $trace,
        ], fn ($v) => $v !== null && $v !== '');

        DB::table('error_logs')->insert([
            'context' => $exception ? class_basename($exception) : 'Application',
            'message' => mb_substr($message, 0, 60000),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);

        return 1;
    }

    /**
     * Code that both Log::error()s and ErrorLog::create()s the same failure
     * (e.g. the Twilio SMS handler) would otherwise show it twice.
     */
    private function alreadyLogged(string $occurredAt, string $message): bool
    {
        try {
            $at = \Carbon\Carbon::parse($occurredAt);
        } catch (\Throwable $e) {
            return false;
        }

        return ErrorLog::query()
            ->whereBetween('occurred_at', [$at->copy()->subSeconds(5), $at->copy()->addSeconds(5)])
            ->where(fn ($q) => $q->whereNull('details')->orWhere('details', 'not like', '%"source":"log file"%'))
            ->pluck('message')
            ->contains(fn ($logged) => $logged !== '' && str_contains($message, (string) $logged));
    }

    /**
     * The tenant id from the record's log context. The context JSON follows
     * the message, so the LAST unescaped ` {"tenant_id":"` before the stack
     * trace is the real one (a quote inside the message or the exception
     * text is JSON-escaped and can't match).
     */
    private function recordTenant(string $body): ?string
    {
        $cut = strpos($body, "\n[stacktrace]");
        $header = $cut === false ? $body : substr($body, 0, $cut);
        $pos = strrpos($header, ' {"tenant_id":"');
        if ($pos === false) {
            return null;
        }

        return preg_match('/^ \{"tenant_id":"([^"\\\\]+)"/', substr($header, $pos), $m) ? $m[1] : null;
    }

    /** Central log folder (config paths are resolved before tenancy suffixes storage_path()). */
    private function logDirectory(): string
    {
        $path = config('logging.channels.single.path') ?: config('logging.channels.daily.path');

        return $path ? dirname($path) : base_path('storage/logs');
    }

    private function statePath(): string
    {
        return storage_path('app/error-log-ingest.json');
    }

    private function loadState(): array
    {
        $raw = @file_get_contents($this->statePath());
        $state = $raw ? json_decode($raw, true) : null;

        return is_array($state) ? array_map('intval', $state) : [];
    }

    private function saveState(array $state): void
    {
        $path = $this->statePath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    }
}
