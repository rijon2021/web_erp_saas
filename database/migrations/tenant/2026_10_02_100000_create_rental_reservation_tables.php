<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 4: reservations (holds).
     *
     * A reservation holds items for a customer over a date range until
     * `hold_until`. While `held` and not past `hold_until` it blocks the items
     * exactly like an active contract; then it is converted into a contract,
     * cancelled, or expires on its own. It carries the same pricing fields as
     * a contract so conversion is lossless.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_reservations')) {
            Schema::create('rental_reservations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('reservation_number', 32)->index();
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->integer('client_id')->index();
                $table->integer('warehouse_id')->nullable()->index();

                $table->enum('status', ['held', 'converted', 'expired', 'cancelled'])->default('held')->index();
                $table->dateTime('start_date')->index();
                $table->dateTime('end_date')->index();
                $table->dateTime('hold_until')->index();

                $table->enum('rate_period', ['hour', 'day', 'week', 'month', 'year'])->default('day');
                $table->enum('billing_type', ['upfront', 'recurring'])->default('upfront');
                $table->unsignedInteger('periods')->nullable();
                $table->decimal('rent_amount', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->decimal('contract_value', 14, 2)->nullable();
                $table->decimal('deposit_amount', 14, 2)->default(0);

                $table->text('notes')->nullable();
                $table->unsignedBigInteger('contract_id')->nullable()->index();
                $table->dateTime('converted_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();

                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_reservation_items')) {
            Schema::create('rental_reservation_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_reservation_id')->index();
                $table->unsignedBigInteger('rental_item_id')->index();
                $table->string('item_name', 191);
                $table->string('item_code', 64)->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('rate', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->timestamps(6);
            });
        }

        if (Schema::hasTable('rental_settings') && ! Schema::hasColumn('rental_settings', 'default_hold_hours')) {
            Schema::table('rental_settings', function (Blueprint $table) {
                // How long a new reservation holds its items by default.
                $table->unsignedSmallInteger('default_hold_hours')->default(48)->after('buffer_hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_settings', 'default_hold_hours')) {
            Schema::table('rental_settings', fn (Blueprint $table) => $table->dropColumn('default_hold_hours'));
        }
        Schema::dropIfExists('rental_reservation_items');
        Schema::dropIfExists('rental_reservations');
    }
};
