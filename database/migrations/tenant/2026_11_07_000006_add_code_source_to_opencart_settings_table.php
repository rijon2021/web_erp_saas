<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations → OpenCart → "Product code source": which OpenCart field is
 * the Stocky product code, the Model (default, as before) or the SKU. Stores
 * that type a category or product type into the required Model box and keep
 * the real article code in SKU pick SKU, so pulled products no longer show
 * that text in the Code column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opencart_settings') || Schema::hasColumn('opencart_settings', 'code_source')) {
            return;
        }

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->string('code_source', 10)->default('model')->after('warehouse_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('opencart_settings') || ! Schema::hasColumn('opencart_settings', 'code_source')) {
            return;
        }

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn('code_source');
        });
    }
};
