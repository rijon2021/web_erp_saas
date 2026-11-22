<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WooCommerceSetting extends Model
{
    protected $table = 'woocommerce_settings';

    protected $fillable = [
        'store_url',
        'consumer_key',
        'consumer_secret',
        'wp_username',
        'wp_app_password',
        'enable_auto_sync',
        'auto_sync_products',
        'auto_sync_stock',
        'sync_interval',
        'last_sync_at',
        'products_per_job',
        'stock_products_per_job',
        'remote_index_page_cap',
        'sku_search_page_cap',
        'allow_alt_sku_on_lookup_conflict',
    ];

    protected $casts = [
        'enable_auto_sync' => 'boolean',
        'auto_sync_products' => 'boolean',
        'auto_sync_stock' => 'boolean',
        'last_sync_at' => 'datetime',
        'products_per_job' => 'integer',
        'stock_products_per_job' => 'integer',
        'remote_index_page_cap' => 'integer',
        'sku_search_page_cap' => 'integer',
        'allow_alt_sku_on_lookup_conflict' => 'boolean',
    ];

    /**
     * Store-specific sync tuning, set per tenant on the WooCommerce settings
     * tab. These values are the column defaults too (migration
     * 2026_10_03_000004); they apply only until that migration has run.
     */
    public const TUNING_DEFAULTS = [
        // Products pushed / pulled per queued batch (a slow WordPress host needs smaller).
        'products_per_job' => 2,
        // Products whose stock is pushed per queued batch.
        'stock_products_per_job' => 3,
        // Pages (100 products each) read when indexing the remote catalogue.
        'remote_index_page_cap' => 500,
        // Pages searched when resolving a SKU that the direct lookup did not find.
        'sku_search_page_cap' => 10,
        // On a SKU clash with an unrelated Woo product, push under an alternate SKU.
        'allow_alt_sku_on_lookup_conflict' => false,
    ];

    /**
     * Field descriptors for the settings tab's "Sync tuning" card, which
     * renders itself from them (sync_options_meta). Max values are the
     * platform's config('woocommerce.tenant_limits').
     */
    public static function tuningMeta(): array
    {
        $limits = (array) config('woocommerce.tenant_limits', []);
        $d = self::TUNING_DEFAULTS;

        $int = fn (string $key, string $group, string $label, string $hint) => [
            'key' => $key, 'group' => $group, 'type' => 'integer', 'label' => $label, 'hint' => $hint,
            'default' => $d[$key], 'min' => 1, 'max' => (int) ($limits[$key] ?? $d[$key]),
        ];

        return [
            'groups' => [
                ['key' => 'batching', 'title' => 'Batch size'],
                ['key' => 'limits', 'title' => 'Lookup limits'],
            ],
            'fields' => [
                $int('products_per_job', 'batching', 'Products per batch',
                    'How many products one sync batch handles. Lower it if batches time out on slow hosting.'),
                $int('stock_products_per_job', 'batching', 'Products per stock batch',
                    'Same, for the stock sync. Each product is one WooCommerce API call.'),
                $int('remote_index_page_cap', 'limits', 'Product index page limit',
                    'Max pages (100 products each) read when matching existing WooCommerce products by SKU. Raise it for very large stores.'),
                $int('sku_search_page_cap', 'limits', 'SKU search page limit',
                    'Max pages scanned when hunting for one product by SKU.'),
                [
                    'key' => 'allow_alt_sku_on_lookup_conflict', 'group' => 'limits', 'type' => 'boolean',
                    'label' => 'Allow alternate SKU on conflict',
                    'hint' => 'When a SKU already belongs to a different WooCommerce product, push under a modified SKU instead of skipping. Leave off unless you know you need it.',
                    'default' => $d['allow_alt_sku_on_lookup_conflict'],
                ],
            ],
        ];
    }

    /**
     * The tuning in effect for a tenant: its saved values, clamped to the
     * platform's config('woocommerce.tenant_limits'). Never reads .env.
     *
     * @return array{products_per_job: int, stock_products_per_job: int, remote_index_page_cap: int, sku_search_page_cap: int, allow_alt_sku_on_lookup_conflict: bool}
     */
    public static function tuning(?self $settings = null): array
    {
        $settings ??= static::first();
        $limits = (array) config('woocommerce.tenant_limits', []);

        $tuning = [];
        foreach (self::TUNING_DEFAULTS as $key => $default) {
            $value = $settings?->getAttribute($key) ?? $default;

            $tuning[$key] = is_bool($default)
                ? (bool) $value
                : max(1, min((int) $value, (int) ($limits[$key] ?? PHP_INT_MAX)));
        }

        return $tuning;
    }
}
