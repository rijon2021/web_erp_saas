<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serial / IMEI tracking applies to standard and variable products only (a
 * combo takes its components' serials, a service holds no stock). Clear the
 * flag a type switch may have left on combos and services, so no screen
 * treats them as serialized products.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'is_imei')) {
            return;
        }

        DB::table('products')
            ->whereIn('type', ['is_combo', 'is_service'])
            ->where('is_imei', 1)
            ->update(['is_imei' => 0]);
    }

    public function down(): void
    {
        // Data clean-up only: the cleared flags are not restored.
    }
};
