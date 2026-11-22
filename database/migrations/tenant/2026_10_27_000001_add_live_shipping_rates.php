<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live carrier rates at checkout (Shippo): a shipping method can be "live"
 * instead of a flat price, and an order keeps the carrier rate it was sold
 * at so the label is later bought for the same service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('shipping_methods', 'type')) {
                $table->string('type', 20)->default('flat');
            }
            if (! Schema::hasColumn('shipping_methods', 'live_settings')) {
                $table->json('live_settings')->nullable();
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'shipping_quote')) {
                $table->json('shipping_quote')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            foreach (['type', 'live_settings'] as $column) {
                if (Schema::hasColumn('shipping_methods', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('online_orders', function (Blueprint $table) {
            if (Schema::hasColumn('online_orders', 'shipping_quote')) {
                $table->dropColumn('shipping_quote');
            }
        });
    }
};
