<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default supplier for a product.
 *
 * Products had no supplier link of any kind: who supplied an item was only
 * inferable by walking purchase_details back to purchases.provider_id, which
 * tells you who you HAVE bought from but not who you SHOULD reorder from.
 * Nullable, because most catalogues will not have one set for a while, and
 * because a product legitimately may not have a single preferred supplier.
 *
 * Paired with settings.product_supplier_enabled — off by default, so the field
 * stays out of the product form until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'provider_id')) {
                $table->integer('provider_id')->nullable()->index('provider_id_products');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'product_supplier_enabled')) {
                $table->boolean('product_supplier_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'provider_id')) {
                $table->dropIndex('provider_id_products');
                $table->dropColumn('provider_id');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'product_supplier_enabled')) {
                $table->dropColumn('product_supplier_enabled');
            }
        });
    }
};
