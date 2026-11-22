<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce: store-specific sync tuning per tenant. These were global
 * WOO_* values in .env, shared by every tenant whatever their store's size or
 * host speed. Defaults match WooCommerceSetting::TUNING_DEFAULTS.
 */
return new class extends Migration
{
    private array $columns = [
        'products_per_job',
        'stock_products_per_job',
        'remote_index_page_cap',
        'sku_search_page_cap',
        'allow_alt_sku_on_lookup_conflict',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('woocommerce_settings')) {
            return;
        }

        Schema::table('woocommerce_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('woocommerce_settings', 'products_per_job')) {
                $table->unsignedInteger('products_per_job')->default(2);
            }
            if (! Schema::hasColumn('woocommerce_settings', 'stock_products_per_job')) {
                $table->unsignedInteger('stock_products_per_job')->default(3);
            }
            if (! Schema::hasColumn('woocommerce_settings', 'remote_index_page_cap')) {
                $table->unsignedInteger('remote_index_page_cap')->default(500);
            }
            if (! Schema::hasColumn('woocommerce_settings', 'sku_search_page_cap')) {
                $table->unsignedInteger('sku_search_page_cap')->default(10);
            }
            if (! Schema::hasColumn('woocommerce_settings', 'allow_alt_sku_on_lookup_conflict')) {
                $table->boolean('allow_alt_sku_on_lookup_conflict')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('woocommerce_settings')) {
            return;
        }

        Schema::table('woocommerce_settings', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('woocommerce_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
