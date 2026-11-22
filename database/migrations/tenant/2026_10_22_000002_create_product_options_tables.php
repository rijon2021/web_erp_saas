<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify-style variant options (Color / Size / ...).
 *
 * Purely additive. product_variants stays the sellable SKU table and every
 * document keeps pointing at product_variant_id. A product "uses options"
 * only when it has rows in product_options; legacy variant products have
 * none and keep behaving exactly as before. product_variants.name remains
 * the display string ("Red / S") so every existing reader is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_options')) {
            Schema::create('product_options', function (Blueprint $table) {
                $table->increments('id');
                // Matches the signed integer PK of products.
                $table->integer('product_id');
                $table->string('name', 100);
                $table->unsignedTinyInteger('position')->default(0);
                $table->timestamps();

                $table->index('product_id', 'product_options_product_idx');
                $table->foreign('product_id', 'product_options_product_fk')
                    ->references('id')->on('products')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('product_option_values')) {
            Schema::create('product_option_values', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('product_option_id');
                $table->string('value', 100);
                $table->unsignedSmallInteger('position')->default(0);
                // Optional colour swatch (hex) for the storefront picker.
                $table->string('swatch', 20)->nullable();
                $table->timestamps();

                $table->index('product_option_id', 'pov_option_idx');
                $table->foreign('product_option_id', 'pov_option_fk')
                    ->references('id')->on('product_options')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('product_variant_option_values')) {
            Schema::create('product_variant_option_values', function (Blueprint $table) {
                // Matches the signed integer PK of product_variants.
                $table->integer('product_variant_id');
                $table->unsignedInteger('product_option_value_id');

                $table->primary(['product_variant_id', 'product_option_value_id'], 'pvov_primary');
                $table->index('product_option_value_id', 'pvov_value_idx');
                $table->foreign('product_variant_id', 'pvov_variant_fk')
                    ->references('id')->on('product_variants')->onDelete('cascade');
                $table->foreign('product_option_value_id', 'pvov_value_fk')
                    ->references('id')->on('product_option_values')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_values');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
    }
};
