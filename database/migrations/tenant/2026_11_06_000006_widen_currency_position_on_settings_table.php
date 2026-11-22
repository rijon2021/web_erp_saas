<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Currency Position gained the no-space variants
 * ('before_nospace' = "SAR100", 'after_nospace' = "100SAR"). Those values are
 * longer than the original VARCHAR(10), so widen the column on tenants that
 * already ran 2026_10_08_000004 (which now creates it at 20 directly).
 * Existing 'before' / 'after' rows keep their meaning and their space.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'currency_position')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->string('currency_position', 20)->default('before')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'currency_position')) {
            return;
        }

        // Any no-space value would not fit back into 10 characters.
        \DB::table('settings')
            ->whereIn('currency_position', ['before_nospace', 'after_nospace'])
            ->update(['currency_position' => \DB::raw("REPLACE(currency_position, '_nospace', '')")]);

        Schema::table('settings', function (Blueprint $table) {
            $table->string('currency_position', 10)->default('before')->change();
        });
    }
};
