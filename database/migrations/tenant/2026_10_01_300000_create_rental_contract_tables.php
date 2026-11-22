<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 3: rental contracts.
     *
     * A contract rents one or more rental items to a rental customer between
     * two dates, priced per `rate_period` (hour…year). Money lives on the
     * contract as agreed figures; the payment schedule and payments arrive in
     * phase 5. Amounts are stored in the base currency.
     *
     * `status` is the lifecycle (draft → active → ended | terminated | renewed,
     * or cancelled). "Upcoming", "expiring soon" and "overdue" are derived from
     * the dates at read time, never stored.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_contracts')) {
            Schema::create('rental_contracts', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('contract_number', 32)->index();
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->integer('client_id')->index();
                $table->integer('warehouse_id')->nullable()->index();

                $table->enum('status', ['draft', 'active', 'ended', 'terminated', 'renewed', 'cancelled'])
                    ->default('draft')->index();

                $table->dateTime('start_date')->index();
                // Null = open-ended (runs until terminated).
                $table->dateTime('end_date')->nullable()->index();

                $table->enum('rate_period', ['hour', 'day', 'week', 'month', 'year'])->default('month');
                // upfront = the whole term is billed once; recurring = billed every period.
                $table->enum('billing_type', ['upfront', 'recurring'])->default('recurring');
                $table->unsignedInteger('periods')->nullable();

                $table->decimal('rent_amount', 14, 2)->default(0);   // items × rates, per period
                $table->decimal('discount', 14, 2)->default(0);      // per bill (whole term when upfront)
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);  // what one bill comes to
                $table->decimal('contract_value', 14, 2)->nullable(); // whole term; null when open-ended
                $table->decimal('deposit_amount', 14, 2)->default(0);

                // Late-payment terms, copied from Rental Settings and editable per contract.
                $table->unsignedSmallInteger('grace_days')->default(0);
                $table->enum('late_fee_type', ['none', 'fixed', 'percent'])->default('none');
                $table->decimal('late_fee_value', 14, 2)->default(0);
                $table->enum('late_fee_frequency', ['once', 'daily'])->default('once');

                $table->longText('terms')->nullable();
                $table->text('notes')->nullable();

                $table->string('signed_name', 191)->nullable();
                $table->string('signature', 191)->nullable();
                $table->dateTime('signed_at')->nullable();

                $table->dateTime('activated_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('terminated_at')->nullable();
                $table->text('termination_reason')->nullable();
                $table->dateTime('cancelled_at')->nullable();

                $table->unsignedBigInteger('renewed_from_id')->nullable()->index();
                $table->unsignedBigInteger('renewal_id')->nullable()->index();

                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_contract_items')) {
            Schema::create('rental_contract_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->unsignedBigInteger('rental_item_id')->index();
                // Snapshot, so the contract still reads right if the item is renamed.
                $table->string('item_name', 191);
                $table->string('item_code', 64)->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('rate', 14, 2)->default(0);   // per rate_period, per unit
                $table->decimal('amount', 14, 2)->default(0); // rate × quantity
                $table->timestamps(6);
            });
        }

        if (! Schema::hasTable('rental_contract_events')) {
            Schema::create('rental_contract_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->string('type', 32);
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps(6);
            });
        }

        if (! Schema::hasTable('rental_contract_attachments')) {
            Schema::create('rental_contract_attachments', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->string('title', 191);
                $table->string('file_name', 191);
                $table->string('original_name', 191)->nullable();
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->integer('uploaded_by')->nullable();
                $table->timestamps(6);
            });
        }

        if (! Schema::hasTable('rental_agreement_templates')) {
            Schema::create('rental_agreement_templates', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('name', 191);
                $table->longText('content')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps(6);
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_agreement_templates');
        Schema::dropIfExists('rental_contract_attachments');
        Schema::dropIfExists('rental_contract_events');
        Schema::dropIfExists('rental_contract_items');
        Schema::dropIfExists('rental_contracts');
    }
};
