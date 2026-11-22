<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car Dealership store theme.
 *
 * vehicle_specs: one row per product that is a car (make, model, year,
 * mileage …) — price, photos, description and stock stay on the product.
 * The columns the inventory filters on are indexed.
 *
 * vehicle_leads: enquiries, test-drive bookings, trade-in valuations,
 * finance enquiries and reservations sent from the storefront.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_specs')) {
            Schema::create('vehicle_specs', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('product_id')->unique();
                $table->string('condition', 20)->default('used'); // new | used | certified
                $table->string('make', 80)->nullable();
                $table->string('model', 80)->nullable();
                $table->string('trim', 120)->nullable();
                $table->unsignedSmallInteger('year')->nullable();
                $table->unsignedInteger('mileage')->nullable();
                $table->string('body_type', 30)->nullable();
                $table->string('fuel', 30)->nullable();
                $table->string('transmission', 30)->nullable();
                $table->string('drivetrain', 30)->nullable();
                $table->decimal('engine_size', 4, 1)->nullable(); // litres
                $table->unsignedSmallInteger('power_hp')->nullable();
                $table->string('exterior_color', 60)->nullable();
                $table->string('interior', 80)->nullable();
                $table->unsignedTinyInteger('doors')->nullable();
                $table->unsignedTinyInteger('seats')->nullable();
                $table->unsignedTinyInteger('owners')->nullable();
                $table->string('fuel_economy', 40)->nullable();
                $table->string('service_history', 20)->nullable(); // full | partial | none
                $table->string('vin', 40)->nullable();
                $table->json('features')->nullable();
                $table->boolean('reservable')->default(true);
                $table->boolean('is_sold')->default(false);
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->index(['make', 'model']);
                $table->index(['year']);
                $table->index(['body_type']);
                $table->index(['condition']);
            });
        }

        if (! Schema::hasTable('vehicle_leads')) {
            Schema::create('vehicle_leads', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('type', 20); // enquiry | test_drive | trade_in | finance | reservation
                $table->integer('product_id')->nullable();
                $table->string('product_name', 190)->nullable();
                $table->integer('client_id')->nullable(); // set when the visitor is signed in
                $table->string('name', 150);
                $table->string('email', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->text('message')->nullable();
                $table->date('preferred_date')->nullable();
                $table->string('preferred_time', 20)->nullable();
                $table->string('trade_plate', 40)->nullable();
                $table->unsignedInteger('trade_mileage')->nullable();
                $table->string('trade_vehicle', 190)->nullable();
                $table->decimal('finance_deposit', 14, 2)->nullable();
                $table->unsignedSmallInteger('finance_term')->nullable();
                $table->boolean('wants_finance')->default(false);
                $table->boolean('has_trade_in')->default(false);
                $table->string('status', 20)->default('new'); // new | contacted | booked | won | lost
                $table->integer('assigned_to')->nullable();
                $table->text('staff_note')->nullable();
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                $table->index(['status']);
                $table->index(['type']);
                $table->index(['product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_leads');
        Schema::dropIfExists('vehicle_specs');
    }
};
