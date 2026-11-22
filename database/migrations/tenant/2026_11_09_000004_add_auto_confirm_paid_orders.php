<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-confirm paid online orders (Online Store > Payment Gateway).
 *
 *  - store_settings.auto_confirm_methods: JSON list of the online gateways
 *    (checkout payment_method codes) whose successful payments confirm the
 *    order straight away — Sale created, stock deducted. NULL / empty = off
 *    for every gateway (the default).
 *  - online_orders.auto_confirm_error: why a paid order could not be
 *    confirmed automatically (e.g. stock ran out), shown to the admin;
 *    cleared once the order is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_settings') && ! Schema::hasColumn('store_settings', 'auto_confirm_methods')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->json('auto_confirm_methods')->nullable();
            });
        }

        if (Schema::hasTable('online_orders') && ! Schema::hasColumn('online_orders', 'auto_confirm_error')) {
            Schema::table('online_orders', function (Blueprint $table) {
                $table->string('auto_confirm_error', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_settings') && Schema::hasColumn('store_settings', 'auto_confirm_methods')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('auto_confirm_methods');
            });
        }

        if (Schema::hasTable('online_orders') && Schema::hasColumn('online_orders', 'auto_confirm_error')) {
            Schema::table('online_orders', function (Blueprint $table) {
                $table->dropColumn('auto_confirm_error');
            });
        }
    }
};
