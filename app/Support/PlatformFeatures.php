<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin → Settings → Features: optional platform features the owner
 * switches on or off. One row per feature in the central `platform_features`
 * table; a feature without a row uses its default below.
 *
 * Adding a feature = one entry in FEATURES, then gate its code paths with
 * PlatformFeatures::enabled('<key>'). Switching a feature off only hides it
 * for new use — nothing is deleted, and switching it back on restores it.
 */
class PlatformFeatures
{
    public const MULTI_CURRENCY = 'multi_currency';
    public const ADDONS = 'addons';

    /**
     * key => [label lang key, description lang key, icon, default, configure route name|null]
     */
    public const FEATURES = [
        self::MULTI_CURRENCY => [
            'label'       => 'super.platform_features.multi_currency',
            'description' => 'super.platform_features.multi_currency_desc',
            'icon'        => 'bi-currency-exchange',
            'default'     => false,
            'configure'   => 'super.settings.currencies',
        ],
        self::ADDONS => [
            'label'       => 'super.platform_features.addons',
            'description' => 'super.platform_features.addons_desc',
            'icon'        => 'bi-puzzle',
            // Already built and wanted by this platform: on unless switched off.
            'default'     => true,
            'configure'   => 'super.addons.index',
        ],
    ];

    /** @var array{rows: array<string, array{on: bool, settings: array}>, at: int}|null */
    protected static ?array $memo = null;

    public static function enabled(string $key): bool
    {
        $row = static::rows()[$key] ?? null;

        return $row !== null ? $row['on'] : (bool) (self::FEATURES[$key]['default'] ?? false);
    }

    /** A feature's own settings (e.g. multi-currency: auto_detect). */
    public static function settings(string $key): array
    {
        return static::rows()[$key]['settings'] ?? [];
    }

    public static function setting(string $key, string $name, mixed $default = null): mixed
    {
        return static::settings($key)[$name] ?? $default;
    }

    public static function tableReady(): bool
    {
        try {
            return Schema::connection('central')->hasTable('platform_features');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Switch a feature on/off (and optionally merge its settings). */
    public static function set(string $key, bool $on, ?array $settings = null, ?int $userId = null): void
    {
        if (! isset(self::FEATURES[$key]) || ! static::tableReady()) {
            return;
        }

        $current = DB::connection('central')->table('platform_features')->where('key', $key)->first();
        $merged = $settings === null
            ? ($current?->settings ?? null)
            : json_encode(array_merge(json_decode((string) ($current?->settings ?? '[]'), true) ?: [], $settings));

        DB::connection('central')->table('platform_features')->updateOrInsert(
            ['key' => $key],
            [
                'is_enabled' => $on,
                'settings'   => $merged,
                'updated_by' => $userId,
                'updated_at' => now(),
                'created_at' => $current?->created_at ?? now(),
            ]
        );

        static::forgetCache();
    }

    /** All features for the Settings → Features tab. */
    public static function all(): array
    {
        $out = [];
        foreach (self::FEATURES as $key => $meta) {
            $out[$key] = $meta + ['key' => $key, 'enabled' => static::enabled($key), 'settings' => static::settings($key)];
        }

        return $out;
    }

    public static function forgetCache(): void
    {
        static::$memo = null;
        try {
            Cache::forget('platform_features:v1');
        } catch (\Throwable) {
            // nothing cached
        }
        SellingCurrencies::forgetCache();
        \App\Services\Billing\PlanPricing::forgetCache();
    }

    /**
     * Rows keyed by feature. Read on every request by gated code, so kept in
     * the cache store for a minute (statics reset per PHP-FPM request).
     */
    protected static function rows(): array
    {
        if (static::$memo !== null && static::$memo['at'] > time() - 60) {
            return static::$memo['rows'];
        }

        $rows = null;
        try {
            $rows = Cache::get('platform_features:v1');
        } catch (\Throwable) {
            $rows = null;
        }

        if (! is_array($rows)) {
            $rows = [];
            if (static::tableReady()) {
                try {
                    foreach (DB::connection('central')->table('platform_features')->get() as $r) {
                        $rows[$r->key] = [
                            'on'       => (bool) $r->is_enabled,
                            'settings' => json_decode((string) ($r->settings ?? ''), true) ?: [],
                        ];
                    }
                } catch (\Throwable) {
                    $rows = [];
                }
            }
            try {
                Cache::put('platform_features:v1', $rows, 60);
            } catch (\Throwable) {
                // not cached
            }
        }

        static::$memo = ['rows' => $rows, 'at' => time()];

        return $rows;
    }
}
