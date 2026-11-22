<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SystemLog extends Model
{
    protected $connection = 'central';

    protected $table = 'system_logs';

    public const TYPE_DNS          = 'dns';
    public const TYPE_SSL          = 'ssl';
    public const TYPE_DATABASE     = 'database';
    public const TYPE_PROVISIONING = 'provisioning';
    public const TYPE_EXCEPTION    = 'exception';
    public const TYPE_GENERAL      = 'general';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_RESOLVED   = 'resolved';

    protected $fillable = [
        'tenant_id',
        'type',
        'severity',
        'message',
        'context',
        'suggested_cause',
        'source',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'occurred_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'resolved_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_DNS          => 'DNS',
            self::TYPE_SSL          => 'SSL',
            self::TYPE_DATABASE     => 'Database',
            self::TYPE_PROVISIONING => 'Provisioning',
            self::TYPE_EXCEPTION    => 'Exception',
            self::TYPE_GENERAL      => 'General',
        ];
    }

    public static function severities(): array
    {
        return [
            self::SEVERITY_INFO     => 'Info',
            self::SEVERITY_WARNING  => 'Warning',
            self::SEVERITY_CRITICAL => 'Critical',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function resolver()
    {
        return $this->belongsTo(CentralUser::class, 'resolved_by');
    }

    /**
     * Message prefixes that are expected noise (bot traffic hitting the raw
     * server IP / unknown Host headers) and must never surface in the
     * Logs & Health UI. Existing rows matching these are hidden by
     * scopeVisible(); new ones are rejected in fromThrowable().
     */
    public const HIDDEN_MESSAGE_PREFIXES = [
        'Tenant could not be identified',
    ];

    public function scopeVisible(Builder $q): Builder
    {
        foreach (self::HIDDEN_MESSAGE_PREFIXES as $prefix) {
            $q->where('message', 'not like', $prefix.'%');
        }

        return $q;
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_UNRESOLVED);
    }

    public function scopeResolved(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_RESOLVED);
    }

    public function scopeCritical(Builder $q): Builder
    {
        return $q->where('severity', self::SEVERITY_CRITICAL);
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Capture a log entry. Safe to call from anywhere; never throws.
     * If the central DB or table is unavailable, silently falls back to file log.
     */
    public static function capture(array $attributes): ?self
    {
        try {
            $attributes = array_merge([
                'type'        => self::TYPE_GENERAL,
                'severity'    => self::SEVERITY_WARNING,
                'status'      => self::STATUS_UNRESOLVED,
                'occurred_at' => now(),
            ], $attributes);

            return static::create($attributes);
        } catch (Throwable $e) {
            @\Log::warning('[system_logs capture failed] ' . $e->getMessage(), [
                'attrs' => $attributes,
            ]);
            return null;
        }
    }

    /**
     * Convert a Throwable into a SystemLog entry.
     */
    public static function fromThrowable(Throwable $e, array $extra = []): ?self
    {
        // Unknown-host noise (bots hitting the server IP) — never worth a log row,
        // regardless of which code path reports it.
        if ($e instanceof \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException) {
            return null;
        }

        $message = mb_substr($e->getMessage() ?: get_class($e), 0, 1000);
        $tenantId = $extra['tenant_id'] ?? null;
        $fingerprint = self::fingerprint($e, $tenantId);

        // The same failure repeating (e.g. every request of a tenant whose
        // database is missing a table) bumps one open row instead of adding
        // thousands of identical ones.
        if ($existing = self::bumpOpenDuplicate($fingerprint, $tenantId)) {
            return $existing;
        }

        $defaults = [
            'type'            => self::TYPE_EXCEPTION,
            'severity'        => self::SEVERITY_CRITICAL,
            'message'         => $message,
            'context'         => [
                'exception'   => get_class($e),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
                'trace'       => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
                'fingerprint' => $fingerprint,
                'occurrences' => 1,
                'first_seen'  => now()->toDateTimeString(),
            ],
            'suggested_cause' => self::suggestedCauseFor($e),
        ];

        return static::capture(array_merge($defaults, $extra));
    }

    /** How many times this row's failure happened (1 for rows written before grouping existed). */
    public function occurrences(): int
    {
        return max(1, (int) ($this->context['occurrences'] ?? 1));
    }

    /**
     * Identity of a failure: tenant + exception class + throw site + message
     * with the volatile parts (numbers, bound values, connection details)
     * stripped, so the same bug on different rows/ids groups together.
     */
    private static function fingerprint(Throwable $e, ?string $tenantId): string
    {
        $msg = (string) $e->getMessage();
        // QueryException: "... (Connection: tenant, Host: ..., SQL: select ...)"
        if (($cut = strpos($msg, ' (Connection:')) !== false) {
            $msg = substr($msg, 0, $cut);
        } elseif (($cut = strpos($msg, ' (SQL:')) !== false) {
            $msg = substr($msg, 0, $cut);
        }
        $msg = preg_replace('/\d+/', '#', $msg);

        return sha1(implode('|', [(string) $tenantId, get_class($e), $e->getFile(), $e->getLine(), $msg]));
    }

    private static function bumpOpenDuplicate(string $fingerprint, ?string $tenantId): ?self
    {
        try {
            $existing = static::query()
                ->where('type', self::TYPE_EXCEPTION)
                ->where('status', self::STATUS_UNRESOLVED)
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('context->fingerprint', $fingerprint)
                ->orderByDesc('id')
                ->first();
            if (! $existing) {
                return null;
            }

            $context = $existing->context ?? [];
            $context['occurrences'] = $existing->occurrences() + 1;
            $context['first_seen'] ??= $existing->occurred_at?->toDateTimeString();
            $existing->context = $context;
            $existing->occurred_at = now(); // "Occurred" = last seen
            $existing->save();

            return $existing;
        } catch (Throwable $ignored) {
            return null; // fall through to a fresh row
        }
    }

    /** Plain-language hint for the failures that have a known fix. */
    private static function suggestedCauseFor(Throwable $e): ?string
    {
        $msg = (string) $e->getMessage();

        if (str_contains($msg, 'SQLSTATE[42S02]') || str_contains($msg, 'SQLSTATE[42S22]')) {
            return 'A database table or column is missing — the tenant database is behind the code. '
                .'Run the pending migrations (Super Admin > System Update, or `php artisan migrate --force` '
                .'then `php artisan tenants:migrate --force`), then resolve this entry.';
        }
        if (str_contains($msg, '[1049]') || str_contains($msg, 'Unknown database')) {
            return 'The tenant database does not exist. Re-run provisioning for this tenant or create its database.';
        }
        if (str_contains($msg, '[1045]')) {
            return 'Access denied — check the database username and password.';
        }
        if (str_contains($msg, '[2002]') || str_contains($msg, 'Connection refused')) {
            return 'Cannot reach the database server. Verify host/port and that the MySQL service is running.';
        }

        return null;
    }
}
