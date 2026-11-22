<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 1: the rentable register (properties,
     * equipment, vehicles…), its categories and the module's settings row.
     *
     * One `rental_items` table serves every kind of rentable thing; the `type`
     * column decides which group of fields the UI shows (address/rooms for a
     * property, serial/brand/meter for equipment and vehicles). Unused fields
     * simply stay null.
     *
     * Photos go to public/images/rentals; `images` holds the file names in
     * display order and the first one is the cover.
     *
     * No DB-level foreign keys (warehouses/categories are referenced by id),
     * matching the fleet/hospital convention — integrity lives in the app layer.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_categories')) {
            Schema::create('rental_categories', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('name', 191);
                // Pre-selects the item type when this category is picked.
                $table->enum('type', ['property', 'equipment', 'vehicle', 'other'])->nullable();
                $table->text('description')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_items')) {
            Schema::create('rental_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');

                $table->string('code', 64)->index();
                $table->string('name', 191);
                $table->unsignedBigInteger('rental_category_id')->nullable()->index();
                $table->enum('type', ['property', 'equipment', 'vehicle', 'other'])->default('equipment')->index();
                $table->enum('status', ['available', 'reserved', 'rented', 'maintenance', 'inactive'])
                    ->default('available')->index();
                $table->enum('condition', ['new', 'excellent', 'good', 'fair', 'poor', 'damaged'])->default('good');
                $table->integer('warehouse_id')->nullable()->index();
                // Identical units rented from one record (e.g. 100 chairs).
                $table->unsignedInteger('quantity')->default(1);

                // Pricing — any period may be left empty when it isn't offered.
                $table->enum('default_period', ['hour', 'day', 'week', 'month', 'year'])->default('day');
                $table->decimal('hourly_rate', 14, 2)->nullable();
                $table->decimal('daily_rate', 14, 2)->nullable();
                $table->decimal('weekly_rate', 14, 2)->nullable();
                $table->decimal('monthly_rate', 14, 2)->nullable();
                $table->decimal('yearly_rate', 14, 2)->nullable();
                $table->decimal('deposit_amount', 14, 2)->default(0);
                $table->unsignedSmallInteger('min_duration')->nullable();
                $table->enum('min_duration_unit', ['hour', 'day', 'week', 'month', 'year'])->nullable();

                // Property details
                $table->string('address', 255)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('floor', 32)->nullable();
                $table->decimal('area', 12, 2)->nullable();
                $table->string('area_unit', 16)->nullable();
                $table->unsignedTinyInteger('bedrooms')->nullable();
                $table->unsignedTinyInteger('bathrooms')->nullable();
                $table->boolean('furnished')->default(false);
                $table->json('amenities')->nullable();

                // Equipment / vehicle details
                $table->string('brand', 100)->nullable();
                $table->string('model', 100)->nullable();
                $table->string('serial_number', 100)->nullable()->index();
                $table->string('plate_number', 64)->nullable();
                $table->unsignedSmallInteger('year')->nullable();
                // Odometer / engine hours, read at every handover and return.
                $table->decimal('meter_reading', 14, 2)->nullable();
                $table->string('meter_unit', 16)->nullable();

                $table->date('purchase_date')->nullable();
                $table->decimal('purchase_cost', 14, 2)->nullable();

                $table->json('images')->nullable();
                $table->text('description')->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();

                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        // Single-row settings table for the module.
        if (! Schema::hasTable('rental_settings')) {
            Schema::create('rental_settings', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');

                $table->string('item_code_prefix', 16)->default('RI');
                $table->string('contract_prefix', 16)->default('RNT');
                $table->string('reservation_prefix', 16)->default('RSV');
                $table->string('invoice_prefix', 16)->default('RINV');

                // Days after a due date before a payment counts as late.
                $table->unsignedSmallInteger('grace_days')->default(3);
                $table->enum('late_fee_type', ['none', 'fixed', 'percent'])->default('none');
                $table->decimal('late_fee_value', 14, 2)->default(0);
                $table->enum('late_fee_frequency', ['once', 'daily'])->default('once');

                $table->decimal('default_deposit', 14, 2)->default(0);
                // Gap kept free between two rentals of the same item.
                $table->unsignedSmallInteger('buffer_hours')->default(0);
                $table->unsignedSmallInteger('expiring_soon_days')->default(30);
                $table->unsignedSmallInteger('reminder_days_before')->default(3);
                $table->text('agreement_terms')->nullable();

                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_settings');
        Schema::dropIfExists('rental_items');
        Schema::dropIfExists('rental_categories');
    }
};
