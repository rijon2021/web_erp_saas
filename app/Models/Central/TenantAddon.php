<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on a tenant bought (or an admin granted): one row per add-on key and
 * purchase. Renewals extend the same row; see App\Services\Billing\AddonService.
 *
 * Access rule: status "active" and (no end date, or the end date is in the
 * future). The tenant still needs a plan that grants access — add-ons are
 * merged INTO the plan (TenantLimitsService), they never replace it.
 */
class TenantAddon extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_addons';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';
    public const STATUS_REVOKED   = 'revoked';

    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_ADMIN    = 'admin';
    public const SOURCE_TRIAL    = 'trial';

    /** billing_cycle of a free trial row. */
    public const CYCLE_TRIAL = 'trial';

    protected $fillable = [
        'tenant_id',
        'addon_key',
        'billing_cycle',
        'amount',
        'currency',
        'status',
        'source',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'payment_link_id',
        'metadata',
        'notes',
        'quantity',
    ];

    protected $casts = [
        'quantity'     => 'integer',
        'amount'       => 'decimal:2',
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata'     => 'array',
    ];

    /** @var array<string, array{keys: string[], limits: array<string,int>, at: int}> active grants per tenant id */
    protected static array $activeKeys = [];

    protected static function booted(): void
    {
        static::saved(fn (self $row) => static::forgetCache($row->tenant_id));
        static::deleted(fn (self $row) => static::forgetCache($row->tenant_id));
    }

    public static function forgetCache(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            static::$activeKeys = [];
        } else {
            unset(static::$activeKeys[$tenantId]);
        }
        \App\Support\StoreThemes::forgetPremium();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class, 'addon_key', 'key');
    }

    public function paymentLink()
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    /** Rows that currently grant access. */
    public function scopeGranting($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isGranting(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function isRecurring(): bool
    {
        return in_array($this->billing_cycle, ['monthly', 'yearly'], true);
    }

    public function isTrial(): bool
    {
        return $this->billing_cycle === self::CYCLE_TRIAL;
    }

    public function isLimitPack(): bool
    {
        return str_starts_with((string) $this->addon_key, Addon::LIMIT_PREFIX);
    }

    /** Units a limit-pack row adds (fixed at purchase, so a later pack-size change never alters it). */
    public function limitUnits(): int
    {
        $units = $this->metadata['units'] ?? null;

        return max(0, (int) ($units ?? (int) ($this->quantity ?? 1)));
    }

    /**
     * Add-on keys that currently grant access to this tenant (features and
     * themes; limit packs are returned by activeGrantsFor). Memoised for a
     * minute per tenant (queue workers serve many tenants), and empty until
     * the add-on migration has run.
     *
     * @return string[]
     */
    public static function activeKeysFor(?string $tenantId): array
    {
        return static::activeGrantsFor($tenantId)['keys'];
    }

    /**
     * Everything this tenant's active add-ons add to its plan:
     * feature/theme keys, and extra units per plan limit (limit packs).
     *
     * @return array{keys: string[], limits: array<string, int>}
     */
    public static function activeGrantsFor(?string $tenantId): array
    {
        if (! $tenantId || ! Addon::tablesReady()) {
            return ['keys' => [], 'limits' => []];
        }

        $hit = static::$activeKeys[$tenantId] ?? null;
        if ($hit && $hit['at'] > time() - 60) {
            return ['keys' => $hit['keys'], 'limits' => $hit['limits']];
        }

        try {
            $grants = static::summarise(static::query()->where('tenant_id', $tenantId)->granting()->get());
        } catch (\Throwable) {
            $grants = ['keys' => [], 'limits' => []];
        }

        static::$activeKeys[$tenantId] = $grants + ['at' => time()];

        return $grants;
    }

    /**
     * Active grants for many tenants in one query (scheduler sweeps).
     *
     * @param  string[]  $tenantIds
     * @return array<string, array{keys: string[], limits: array<string, int>}>
     */
    public static function activeGrantsByTenant(array $tenantIds): array
    {
        if ($tenantIds === [] || ! Addon::tablesReady()) {
            return [];
        }

        try {
            return static::query()->whereIn('tenant_id', $tenantIds)->granting()->get()
                ->groupBy('tenant_id')
                ->map(fn ($rows) => static::summarise($rows))
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{keys: string[], limits: array<string, int>} */
    protected static function summarise(iterable $rows): array
    {
        $keys = [];
        $limits = [];
        foreach ($rows as $row) {
            /** @var self $row */
            if ($row->isLimitPack()) {
                $limit = substr($row->addon_key, strlen(Addon::LIMIT_PREFIX));
                $limits[$limit] = ($limits[$limit] ?? 0) + $row->limitUnits();
            } else {
                $keys[$row->addon_key] = true;
            }
        }

        return ['keys' => array_keys($keys), 'limits' => $limits];
    }

    public function displayName(): string
    {
        $catalog = Addon::catalog();

        $name = $this->addon?->displayName()
            ?? ($catalog[$this->addon_key]['label'] ?? $this->addon_key);

        // Limit packs: show what the row adds, e.g. "Extra Users (+10)".
        return $this->isLimitPack() ? $name . ' (+' . $this->limitUnits() . ')' : $name;
    }
}
