<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional barcodes per product (or per variant): the manufacturer's EAN on
 * the box, a supplier's code, an old code — each one scans to the product at
 * the register and in the document forms. The product code stays the primary
 * code; a barcode is unique across these rows, product codes and variant codes
 * (enforced in App\Services\ProductBarcodes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_barcodes')) {
            return;
        }

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->string('barcode', 64);
            $table->timestamps();

            $table->unique('barcode');
            $table->index(['product_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
