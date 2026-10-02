<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products that belong to a warehouse (Settings → Features → "Restrict
 * products by warehouse").
 *
 * Every product gets a stock row in EVERY warehouse when it is created, so
 * "has a row in my warehouse" cannot tell whose product it is — a car added
 * by the branch that sells cars showed up, at quantity zero, for the branch
 * that sells phones. products.warehouse_id records the home warehouse the
 * product was created for. When the switch is on, a user assigned to specific
 * warehouses sees a product only if its home warehouse is one of theirs, or
 * it has real stock (qte > 0) in one of theirs (it was transferred or
 * purchased in). Users who may see all warehouses are never restricted.
 *
 * restrict_products_by_warehouse defaults to FALSE so existing installs see no
 * change until an admin opts in.
 *
 * Backfill: a product stocked in exactly one warehouse is assigned to it.
 * Products stocked nowhere or in several warehouses stay unassigned (visible
 * to unrestricted users only while the switch is on) for an admin to sort out
 * from the product form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'restrict_products_by_warehouse')) {
                $table->boolean('restrict_products_by_warehouse')->default(false);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'warehouse_id')) {
                $table->integer('warehouse_id')->nullable()->index('warehouse_id_products')->after('brand_id');
            }
        });

        // One-time backfill: products with stock in exactly one warehouse.
        DB::statement('
            UPDATE products p
            INNER JOIN (
                SELECT product_id, MIN(warehouse_id) AS warehouse_id, COUNT(DISTINCT warehouse_id) AS warehouses
                FROM product_warehouse
                WHERE deleted_at IS NULL AND qte > 0
                GROUP BY product_id
            ) s ON s.product_id = p.id
            SET p.warehouse_id = s.warehouse_id
            WHERE p.warehouse_id IS NULL AND s.warehouses = 1
        ');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'warehouse_id')) {
                $table->dropIndex('warehouse_id_products');
                $table->dropColumn('warehouse_id');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'restrict_products_by_warehouse')) {
                $table->dropColumn('restrict_products_by_warehouse');
            }
        });
    }
};
