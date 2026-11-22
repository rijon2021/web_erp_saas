<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Features → "Different Prices per Store".
 *
 * A product (or one of its variants) can carry its own retail, wholesale and
 * minimum price in a given warehouse / branch. A NULL column means "use the
 * product's normal price", so a row only needs the figures that differ.
 * Sales, quotations, the POS and barcode labels read the price of the
 * document's warehouse; the online store and channel syncs keep the normal
 * price. Sales already recorded keep their line prices.
 *
 * Kept in its own table rather than on product_warehouse: product edits
 * soft-delete and recreate the stock rows whenever variants change, which
 * would silently wipe prices stored there.
 *
 * product_variant_id is NULL for a standard product. MySQL does not treat
 * NULLs as equal in a UNIQUE index, so one row per product/variant/warehouse
 * is enforced by the writers (they replace, never append).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'warehouse_prices_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('warehouse_prices_enabled')->default(false);
            });
        }

        if (! Schema::hasTable('product_warehouse_prices')) {
            Schema::create('product_warehouse_prices', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('product_id');
                $table->integer('product_variant_id')->nullable();
                $table->integer('warehouse_id');
                $table->decimal('price', 15, 3)->nullable();
                $table->decimal('wholesale_price', 15, 3)->nullable();
                $table->decimal('min_price', 15, 3)->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'product_id'], 'pwp_warehouse_product');
                $table->index(['product_id', 'product_variant_id'], 'pwp_product_variant');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouse_prices');

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'warehouse_prices_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('warehouse_prices_enabled');
            });
        }
    }
};
