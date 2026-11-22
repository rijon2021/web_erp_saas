<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Hide Zero Decimals: when on, a whole-number amount is shown
 * without its fraction (100.00 → 100; 100.50 keeps its decimals) on every
 * screen, receipt, PDF and storefront price (App\utils\helpers::format_price,
 * priceFormat.js formatPriceDisplay). Display only: stored values, API
 * payloads and calculations are untouched. Default off keeps the historical
 * fixed-decimal rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || Schema::hasColumn('settings', 'hide_zero_decimals')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('hide_zero_decimals')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'hide_zero_decimals')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('hide_zero_decimals');
        });
    }
};
