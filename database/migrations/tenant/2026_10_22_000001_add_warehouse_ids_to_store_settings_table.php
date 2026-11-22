<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store Settings > Warehouses: the warehouses the online store sells from.
 * NULL = all warehouses. Existing stores keep selling from their single
 * default warehouse until the admin widens the selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_settings') || Schema::hasColumn('store_settings', 'warehouse_ids')) {
            return;
        }

        Schema::table('store_settings', function (Blueprint $table) {
            $table->json('warehouse_ids')->nullable()->after('default_warehouse_id');
        });

        $defaultId = DB::table('store_settings')->value('default_warehouse_id');
        if ($defaultId) {
            DB::table('store_settings')->update([
                'warehouse_ids' => json_encode([(int) $defaultId]),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_settings') && Schema::hasColumn('store_settings', 'warehouse_ids')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('warehouse_ids');
            });
        }
    }
};
