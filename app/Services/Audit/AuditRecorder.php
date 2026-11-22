<?php

namespace App\Services\Audit;

use App\Http\Controllers\ImpersonationController;
use App\Models\Central\ImpersonationLog;
use App\Models\Central\TenantSubscription;
use App\Models\User;
use App\Services\TenantLimitsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Writes audit_logs rows. Bound as a scoped singleton (one per request / per
 * queued job). Every public method is safe to call anywhere: auditing never
 * throws into the caller and is a no-op unless the tenant's plan has the
 * `audit_log` feature and the table exists.
 */
class AuditRecorder
{
    public const FEATURE = 'audit_log';

    /** @var array<string, bool> tenant id => recording enabled */
    private array $enabled = [];

    /** @var array<string, true> "type:id:event" written in this scope */
    private array $written = [];

    private ?string $batch = null;

    private ?string $tenantKey = null;

    /** @var array{0: ?int, 1: ?string}|null */
    private ?array $actor = null;

    private ?string $impersonator = null;

    private bool $impersonatorResolved = false;

    public function enabled(): bool
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return false;
        }

        $tenantId = (string) tenant('id');
        if ($this->tenantKey !== $tenantId) {
            // Commands that loop over tenants reuse this instance.
            $this->tenantKey = $tenantId;
            $this->written = [];
            $this->batch = null;
            $this->actor = null;
            $this->impersonator = null;
            $this->impersonatorResolved = false;
        }

        return $this->enabled[$tenantId] ??= $this->resolveEnabled();
    }

    private function resolveEnabled(): bool
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return false;
            }

            // Over HTTP the limits service is per request and usually already
            // resolved by tenant.feature. A queue worker keeps that singleton
            // across tenants, so look the plan up directly there.
            if (! app()->runningInConsole()) {
                return app(TenantLimitsService::class)->hasFeature(self::FEATURE);
            }

            $plan = TenantSubscription::where('tenant_id', tenant('id'))
                ->grantingAccess()->with('plan')->latest()->first()?->plan;
            // Plus the tenant's add-ons, as TenantLimitsService does.
            $grants = \App\Models\Central\TenantAddon::activeGrantsFor((string) tenant('id'));
            $plan = $plan?->withAddonFeatures($grants['keys'], $grants['limits']);

            return (bool) $plan?->hasFeature(self::FEATURE);
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Model changes (AuditObserver)
    // ------------------------------------------------------------------

    public function created(Model $model): void
    {
        $this->guard(function () use ($model) {
            $type = AuditRegistry::typeFor($model);
            $values = $this->filter($type, $model->getAttributes(), true);

            $this->write('created', $type, $model->getKey(), $this->label($type, $model->getAttributes()), null, $values);
        });
    }

    public function updated(Model $model): void
    {
        $this->guard(function () use ($model) {
            $this->transition(AuditRegistry::typeFor($model), $model->getKey(), $model->getRawOriginal(), $model->getAttributes());
        });
    }

    public function deleted(Model $model): void
    {
        $this->guard(function () use ($model) {
            $this->deletedRow(AuditRegistry::typeFor($model), $model->getKey(), $model->getAttributes());
        });
    }

    /** Log one row going from $before to $after (raw attribute arrays). */
    private function transition(string $type, $id, array $before, array $after): void
    {
        // Soft delete / restore.
        if (array_key_exists('deleted_at', $after)) {
            $wasDeleted = ($before['deleted_at'] ?? null) !== null;
            $isDeleted = $after['deleted_at'] !== null;
            if ($isDeleted && ! $wasDeleted) {
                $this->deletedRow($type, $id, $after + $before);

                return;
            }
            if (! $isDeleted && $wasDeleted) {
                $this->write('restored', $type, $id, $this->label($type, $after + $before), null, null);

                return;
            }
        }

        $old = [];
        $new = [];
        foreach ($after as $attr => $value) {
            if (AuditRegistry::isIgnored($type, $attr)) {
                continue;
            }
            $previous = $before[$attr] ?? null;
            if ($this->same($previous, $value)) {
                continue;
            }
            if (AuditRegistry::isSecret($attr)) {
                $old[$attr] = $new[$attr] = '••••••';

                continue;
            }
            $old[$attr] = $this->clip($previous);
            $new[$attr] = $this->clip($value);
        }

        if ($new) {
            $this->write('updated', $type, $id, $this->label($type, $after + $before), $old, $new);
        }
    }

    private function deletedRow(string $type, $id, array $attributes): void
    {
        $this->write('deleted', $type, $id, $this->label($type, $attributes), $this->filter($type, $attributes, true), null);
    }

    // ------------------------------------------------------------------
    // Writes done with a query (AuditQueryWrites middleware)
    // ------------------------------------------------------------------

    /**
     * The rows a write request is about to change, read before it runs.
     *
     * @return array<int, array> id => raw attributes
     */
    public function snapshot(string $type, array $ids): array
    {
        $rows = [];
        $this->guard(function () use ($type, $ids, &$rows) {
            $rows = $this->rows($type, $ids);
        });

        return $rows;
    }

    /**
     * After the request: log each row the observer did not already log, from
     * its before/after pair. Gone = hard-deleted (logged with the values it
     * had); deleted_at newly set = soft-deleted; otherwise the field diff
     * (nothing when the controller changed nothing, e.g. refused a delete).
     */
    public function reconcile(string $type, array $ids, array $before): void
    {
        $this->guard(function () use ($type, $ids, $before) {
            $ids = array_values(array_filter($this->ids($ids), fn ($id) => ! $this->logged($type, $id)));
            if (! $ids) {
                return;
            }

            $after = $this->rows($type, $ids);
            foreach ($ids as $id) {
                $pre = $before[$id] ?? null;
                $post = $after[$id] ?? null;
                if ($pre === null) {
                    continue; // nothing to compare with (not there before the request)
                }
                if ($post === null) {
                    $this->deletedRow($type, $id, $pre);
                } else {
                    $this->transition($type, $id, $pre, $post);
                }
            }
        });
    }

    /** @return array<int, array> */
    private function rows(string $type, array $ids): array
    {
        $class = AuditRegistry::classFor($type);
        $ids = $this->ids($ids);
        if (! $class || ! $ids) {
            return [];
        }

        $model = new $class;
        $key = $model->getKeyName();

        return DB::table($model->getTable())->whereIn($key, $ids)->get()
            ->mapWithKeys(fn ($row) => [(int) $row->{$key} => (array) $row])
            ->all();
    }

    /** Positive integer ids, deduplicated, capped (bulk actions). */
    private function ids(array $ids): array
    {
        $ids = array_filter(array_map(fn ($id) => is_numeric($id) ? (int) $id : 0, $ids), fn ($id) => $id > 0);

        return array_slice(array_values(array_unique($ids)), 0, 500);
    }

    /** Whether this scope already logged a change to that row. */
    private function logged(string $type, int $id): bool
    {
        foreach (['updated', 'deleted', 'restored'] as $event) {
            if (isset($this->written["$type:$id:$event"])) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Sign-in events (AuditAuthEvents)
    // ------------------------------------------------------------------

    public function auth(string $event, ?User $user, ?string $attemptedLogin = null): void
    {
        $this->guard(function () use ($event, $user, $attemptedLogin) {
            if ($user) {
                $this->actor = [(int) $user->id, $this->nameOf($user)];
            }
            $new = $attemptedLogin !== null ? ['login' => mb_substr($attemptedLogin, 0, 191)] : null;

            $this->write($event, $user ? 'user' : null, $user?->id, $user ? $this->nameOf($user) : null, null, $new);
        });
    }

    // ------------------------------------------------------------------

    /** Run $fn only when recording, swallowing any failure. */
    private function guard(callable $fn): void
    {
        try {
            if ($this->enabled()) {
                $fn();
            }
        } catch (\Throwable $e) {
            // Auditing must never break the request.
        }
    }

    private function write(string $event, ?string $type, $id, ?string $label, ?array $old, ?array $new): void
    {
        if ($type !== null && $id !== null) {
            $this->written["$type:$id:$event"] = true;
        }

        [$userId, $userName] = $this->actor();
        // A routed request = someone using the app; commands and queued jobs have none.
        $request = app()->bound('request') && request()->route() ? request() : null;
        $http = $request !== null;

        DB::table('audit_logs')->insert([
            'event' => $event,
            'subject_type' => $type,
            'subject_id' => $id !== null ? (int) $id : null,
            'subject_label' => $label,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            'new_values' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            'user_id' => $userId,
            'user_name' => $userName,
            'impersonator' => $this->impersonator($userId),
            // "api" = a call with a Settings > API key (ApiKeyAuth marks the request).
            'channel' => $http ? ($request->attributes->get('api_key_id') ? 'api' : 'web') : 'system',
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'method' => $request?->method(),
            'url' => $request ? mb_substr('/'.ltrim($request->path(), '/'), 0, 255) : null,
            'batch' => $this->batch ??= (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    /** @return array{0: ?int, 1: ?string} */
    private function actor(): array
    {
        if ($this->actor !== null) {
            return $this->actor;
        }

        $user = null;
        try {
            $user = Auth::guard('api')->user() ?? Auth::guard('web')->user();
        } catch (\Throwable) {
            // no guard / no request
        }

        // Not memoised while nobody is signed in: a login later in the same
        // request must be attributed to that user.
        return $user ? ($this->actor = [(int) $user->id, $this->nameOf($user)]) : [null, null];
    }

    private function nameOf($user): string
    {
        $name = $user->username ?: trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

        return mb_substr($name !== '' ? $name : (string) $user->email, 0, 191);
    }

    /** Name of the super admin behind an active "Login as" session, if any. */
    private function impersonator(?int $userId): ?string
    {
        if ($this->impersonatorResolved || $userId === null) {
            return $this->impersonator;
        }
        $this->impersonatorResolved = true;

        try {
            if (Cache::get(ImpersonationController::CACHE_FLAG)) {
                $log = ImpersonationLog::where('tenant_id', (string) tenant('id'))
                    ->where('user_id', $userId)
                    ->where('status', ImpersonationLog::STATUS_ACTIVE)
                    ->latest('id')->first();
                $this->impersonator = $log ? mb_substr($log->admin_name ?: $log->admin_email, 0, 191) : null;
            }
        } catch (\Throwable) {
            $this->impersonator = null;
        }

        return $this->impersonator;
    }

    private function label(string $type, array $attributes): ?string
    {
        return AuditRegistry::labelFor($type, $attributes);
    }

    /** Drop ignored attributes, mask secrets, clip long text (and, for snapshots, blanks). */
    private function filter(string $type, array $attributes, bool $dropBlank): array
    {
        $out = [];
        foreach ($attributes as $attr => $value) {
            if (AuditRegistry::isIgnored($type, $attr)) {
                continue;
            }
            if ($dropBlank && ($value === null || $value === '')) {
                continue;
            }
            $out[$attr] = AuditRegistry::isSecret($attr) ? '••••••' : $this->clip($value);
        }

        return $out;
    }

    /** "10" vs "10.00", null vs "" — not a change anyone made. */
    private function same($a, $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }
        if (is_bool($a) || is_bool($b)) {
            return (int) $a === (int) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) $a === (string) $b;
    }

    private function clip($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if (is_string($value) && mb_strlen($value) > AuditRegistry::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, AuditRegistry::MAX_VALUE_LENGTH).'…';
        }

        return $value;
    }
}
