<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management, phase 2: booking details for service products.
 *
 * A service product (products.type = is_service) becomes bookable once it has
 * a bk_services row. Name, price, tax, category and image stay on the
 * product; the row adds how long it takes, the gaps kept around it, how many
 * customers share one time (seats > 1 = a class), how the price is shown,
 * which booking modes it accepts and whether the website lists it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bk_services')) {
            return;
        }
        Schema::create('bk_services', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('product_id')->unique('bk_services_product_id');
            $table->string('business_type', 30)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('buffer_before')->default(0);
            $table->unsignedSmallInteger('buffer_after')->default(0);
            $table->unsignedSmallInteger('seats')->default(1);
            $table->string('price_display', 10)->default('from');   // from | fixed | hidden
            $table->string('booking_modes', 10)->default('both');   // both | slot | request
            $table->boolean('show_on_website')->default(true);
            $table->boolean('featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('short_description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_services');
    }
};
