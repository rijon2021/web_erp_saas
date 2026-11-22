<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce: opt-in automatic pushes — a product created or edited in the
 * POS, and stock that moved (sale, purchase, return, adjustment, transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('woocommerce_settings', 'auto_sync_products')) {
                $table->boolean('auto_sync_products')->default(false)->after('enable_auto_sync');
            }
            if (! Schema::hasColumn('woocommerce_settings', 'auto_sync_stock')) {
                $table->boolean('auto_sync_stock')->default(false)->after('auto_sync_products');
            }
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_settings', function (Blueprint $table) {
            foreach (['auto_sync_products', 'auto_sync_stock'] as $column) {
                if (Schema::hasColumn('woocommerce_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
