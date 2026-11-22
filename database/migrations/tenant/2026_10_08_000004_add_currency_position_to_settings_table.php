<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Currency Position: whether the currency symbol is shown
 * before ('before', "SAR 100"; 'before_nospace', "SAR100") or after ('after',
 * "100 SAR"; 'after_nospace', "100SAR") the amount on
 * every screen, receipt and PDF (App\utils\helpers::with_currency, priceFormat.js
 * withCurrency). Default 'before' keeps the historical rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || Schema::hasColumn('settings', 'currency_position')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->string('currency_position', 20)->default('before');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'currency_position')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('currency_position');
        });
    }
};
