<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS favourite products: each cashier stars the products they sell most and
 * can filter the POS grid down to them. product_variant_id is 0 (not NULL) for
 * products without variants so the unique key also covers those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_favorite_products')) {
            return;
        }

        Schema::create('pos_favorite_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'product_id', 'product_variant_id'], 'pos_fav_user_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_favorite_products');
    }
};
