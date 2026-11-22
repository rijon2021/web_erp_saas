<?php

namespace App\Models\Central;

use App\Support\StoreThemes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One entry of the super admin's add-on catalogue: a plan feature (module)
 * or a premium store theme that tenants can buy on top of their plan.
 *
 * Keys: a Plan::AVAILABLE_FEATURES key ("hrm", "crm", …) or "theme:<key>"
 * for a theme in StoreThemes::PREMIUM. A bought add-on simply adds its key to
 * the tenant's effective plan features (Plan::withAddonFeatures), so every
 * existing feature gate — routes, sidebar, permissions, themes — follows.
 */
class Addon extends Model
{
    protected $connection = 'central';

    protected $table = 'addons';

    public const TYPE_FEATURE = 'feature';
    public const TYPE_THEME = 'theme';
    public const TYPE_LIMIT = 'limit';

    public const THEME_PREFIX = 'theme:';
    /** Limit packs: "limit:max_users" adds pack_size users per pack bought. */
    public const LIMIT_PREFIX = 'limit:';

    /** Packs one purchase may contain. */
    public const MAX_PACKS = 100;

    /** Billing options, in display order. */
    public const CYCLES = ['monthly', 'yearly', 'lifetime'];

    protected $fillable = [
        'key',
        'type',
        'name',
        'description',
        'monthly_price',
        'yearly_price',
        'lifetime_price',
        'is_active',
        'plan_ids',
        'sort_order',
        'trial_days',
        'requires',
        'pack_size',
    ];

    protected $casts = [
        'monthly_price'  => 'decimal:2',
        'yearly_price'   => 'decimal:2',
        'lifetime_price' => 'decimal:2',
        'is_active'      => 'boolean',
        'plan_ids'       => 'array',
        'sort_order'     => 'integer',
        'trial_days'     => 'integer',
        'requires'       => 'array',
        'pack_size'      => 'integer',
    ];

    /** @var array{ready: bool, at: int}|null */
    protected static ?array $ready = null;

    /** @var array{ready: bool, at: int}|null second migration (trials, packs, requires) */
    protected static ?array $readyV2 = null;

    /**
     * Whether 2026_11_14_000003 has run (trial_days / requires / pack_size /
     * tenant_addons.quantity). Without it trials, limit packs and requirements
     * are simply off.
     */
    public static function extrasReady(): bool
    {
        if (! static::tablesReady()) {
            return false;
        }
        if (static::$readyV2 !== null && static::$readyV2['at'] > time() - 300) {
            return static::$readyV2['ready'];
        }

        $ready = static::cachedFlag('extras', function () {
            return Schema::connection('central')->hasColumn('addons', 'trial_days')
                && Schema::connection('central')->hasColumn('tenant_addons', 'quantity');
        });

        static::$readyV2 = ['ready' => $ready, 'at' => time()];

        return $ready;
    }

    public function isLimitPack(): bool
    {
        return str_starts_with((string) $this->key, self::LIMIT_PREFIX);
    }

    /** The plan limit a pack raises, e.g. "max_users". */
    public function limitKey(): ?string
    {
        return $this->isLimitPack() ? substr((string) $this->key, strlen(self::LIMIT_PREFIX)) : null;
    }

    /** Units one pack adds (limit packs). */
    public function packSize(): int
    {
        return max(1, (int) ($this->pack_size ?? 1));
    }

    /** Free trial length in days (0 = no trial). Never for limit packs. */
    public function trialDays(): int
    {
        return static::extrasReady() && ! $this->isLimitPack() ? max(0, (int) ($this->trial_days ?? 0)) : 0;
    }

    /** @return string[] add-on keys that must be active before this one can be bought */
    public function requiredKeys(): array
    {
        if (! static::extrasReady()) {
            return [];
        }

        return array_values(array_filter(array_map('strval', (array) ($this->requires ?? []))));
    }

    /** @var array<string, array{keys: string[], at: int}> keys for sale, per plan id */
    protected static array $saleKeys = [];

    /**
     * Whether the add-on tables exist (migration run). Every tenant page load
     * asks, so the answer is kept in the cache store (statics alone reset on
     * each PHP-FPM request): "ready" for 10 minutes, "not yet" for 1 minute so
     * running the migration takes effect quickly. The migrations clear it too.
     */
    public static function tablesReady(): bool
    {
        if (static::$ready !== null && static::$ready['at'] > time() - 300) {
            return static::$ready['ready'];
        }

        $ready = static::cachedFlag('tables', function () {
            return Schema::connection('central')->hasTable('addons')
                && Schema::connection('central')->hasTable('tenant_addons');
        });

        static::$ready = ['ready' => $ready, 'at' => time()];

        return $ready;
    }

    /**
     * Selling add-ons is switched on (Settings → Features) and migrated. When
     * off, nothing new is offered or sold; add-ons tenants already have keep
     * working and can still be renewed.
     */
    public static function marketplaceOn(): bool
    {
        return static::tablesReady()
            && \App\Support\PlatformFeatures::enabled(\App\Support\PlatformFeatures::ADDONS);
    }

    /** A schema probe answered from the cache store when possible. */
    protected static function cachedFlag(string $name, callable $probe): bool
    {
        $key = 'addons:schema:' . $name;
        try {
            $hit = \Illuminate\Support\Facades\Cache::get($key);
            if ($hit !== null) {
                return (bool) $hit;
            }
        } catch (\Throwable) {
            // cache unavailable — probe below
        }

        try {
            $ready = (bool) $probe();
        } catch (\Throwable) {
            $ready = false;
        }

        try {
            \Illuminate\Support\Facades\Cache::put($key, $ready ? 1 : 0, $ready ? 600 : 60);
        } catch (\Throwable) {
            // not cached — fine
        }

        return $ready;
    }

    public static function forgetCache(): void
    {
        static::$saleKeys = [];
        static::$ready = null;
        static::$readyV2 = null;
        static::$catalogMemo = [];
        try {
            \Illuminate\Support\Facades\Cache::forget('addons:schema:tables');
            \Illuminate\Support\Facades\Cache::forget('addons:schema:extras');
        } catch (\Throwable) {
            // nothing cached
        }
        TenantAddon::forgetCache();
    }

    protected static function booted(): void
    {
        // Prices / availability changed: only the for-sale memo is stale
        // (the schema readiness flags are not).
        static::saved(function () {
            static::$saleKeys = [];
            TenantAddon::forgetCache();
        });
        static::deleted(function () {
            static::$saleKeys = [];
            TenantAddon::forgetCache();
        });
    }

    /**
     * Everything that can be sold, keyed by add-on key: every plan feature
     * plus every premium theme. Labels are the translated feature labels.
     *
     * @return array<string, array{key: string, type: string, label: string, description: string, icon: string}>
     */
    public static function catalog(): array
    {
        // Built from constants + translations only (no DB), and read many times
        // per page (every add-on row, every tenant add-on name): memoise per
        // locale and per "extras migrated" state.
        $memoKey = app()->getLocale() . '|' . (static::extrasReady() ? 1 : 0);
        if (isset(static::$catalogMemo[$memoKey])) {
            return static::$catalogMemo[$memoKey];
        }

        return static::$catalogMemo[$memoKey] = static::buildCatalog();
    }

    /** @var array<string, array> catalog() per locale */
    protected static array $catalogMemo = [];

    protected static function buildCatalog(): array
    {
        $out = [];

        foreach (Plan::availableFeatures() as $key => $meta) {
            // The premium_themes feature (all premium themes at once) stays a
            // plan option; single themes are sold as theme:<key> below.
            if ($key === StoreThemes::PREMIUM_FEATURE) {
                continue;
            }
            $out[$key] = [
                'key'         => $key,
                'type'        => self::TYPE_FEATURE,
                'label'       => (string) ($meta['label'] ?? Str::headline($key)),
                'description' => (string) ($meta['description'] ?? ''),
                'icon'        => (string) ($meta['icon'] ?? 'bi-puzzle'),
            ];
        }

        foreach (array_keys(StoreThemes::PREMIUM) as $theme) {
            $key = self::THEME_PREFIX . $theme;
            $out[$key] = [
                'key'         => $key,
                'type'        => self::TYPE_THEME,
                'label'       => __('super.addons.theme_label', ['name' => Str::headline($theme)]),
                'description' => __('super.addons.theme_description'),
                'icon'        => 'bi-gem',
            ];
        }

        // Limit packs (only once the second add-on migration has run).
        if (static::extrasReady()) {
            foreach (Plan::availableLimits() as $limit => $meta) {
                $key = self::LIMIT_PREFIX . $limit;
                $out[$key] = [
                    'key'         => $key,
                    'type'        => self::TYPE_LIMIT,
                    'label'       => __('super.addons.limit_label', ['name' => (string) ($meta['label'] ?? Str::headline($limit))]),
                    'description' => __('super.addons.limit_description'),
                    'icon'        => (string) ($meta['icon'] ?? 'bi-plus-square'),
                ];
            }
        }

        return $out;
    }

    /** Catalogue metadata for this row's key (null when the key no longer exists). */
    public function meta(): ?array
    {
        return static::catalog()[$this->key] ?? null;
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->meta()['label'] ?? Str::headline((string) $this->key));
    }

    public function displayDescription(): string
    {
        return $this->description ?: ($this->meta()['description'] ?? '');
    }

    /** Price for a billing option, or null when that option is not offered. */
    public function priceFor(string $cycle): ?float
    {
        $value = match ($cycle) {
            'monthly'  => $this->monthly_price,
            'yearly'   => $this->yearly_price,
            'lifetime' => $this->lifetime_price,
            default    => null,
        };

        return $value === null ? null : (float) $value;
    }

    /** @return string[] the billing options this add-on is sold with */
    public function cycles(): array
    {
        return array_values(array_filter(self::CYCLES, fn ($c) => $this->priceFor($c) !== null));
    }

    public function isForSale(): bool
    {
        return $this->is_active && $this->cycles() !== [] && $this->meta() !== null
            && (! $this->isLimitPack() || static::extrasReady());
    }

    /** May a tenant on this plan buy it? */
    public function offeredToPlan(?Plan $plan): bool
    {
        if (! $plan) {
            return false;
        }

        $ids = $this->plan_ids;

        return ! is_array($ids) || $ids === [] || in_array((int) $plan->id, array_map('intval', $ids), true);
    }

    /**
     * Add-on keys a tenant on this plan could buy (for sale + offered to the
     * plan), memoised for a minute — read on every page for the sidebar.
     *
     * @return string[]
     */
    public static function saleKeysForPlan(?Plan $plan): array
    {
        if (! $plan || ! static::marketplaceOn()) {
            return [];
        }

        $memoKey = (string) $plan->id;
        $hit = static::$saleKeys[$memoKey] ?? null;
        if ($hit && $hit['at'] > time() - 60) {
            return $hit['keys'];
        }

        try {
            $keys = static::query()->where('is_active', true)->get()
                ->filter(fn (self $a) => $a->isForSale() && $a->offeredToPlan($plan))
                ->pluck('key')->values()->all();
        } catch (\Throwable) {
            $keys = [];
        }

        static::$saleKeys[$memoKey] = ['keys' => $keys, 'at' => time()];

        return $keys;
    }
}
