<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 5: invoices and payments.
     *
     * Invoices are issued per billing period from each contract (one for the
     * whole term when billed upfront), plus manual "charge" invoices. A payment
     * is split across invoices through rental_payment_allocations, so one
     * payment can settle several periods. Amounts are in the base currency.
     *
     * The unique (contract, kind, sequence) key stops two concurrent billing
     * runs from issuing the same period twice; charges have no sequence.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_invoices')) {
            Schema::create('rental_invoices', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('invoice_number', 32)->index();
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->integer('client_id')->index();

                $table->enum('kind', ['rent', 'charge'])->default('rent');
                $table->unsignedInteger('sequence')->nullable(); // period index, rent only
                $table->string('description', 255)->nullable();
                $table->dateTime('period_start')->nullable();
                $table->dateTime('period_end')->nullable();
                $table->date('issue_date');
                $table->date('due_date')->index();

                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);       // what the period costs
                $table->decimal('late_fee', 14, 2)->default(0);     // accrued, per the contract's terms
                $table->boolean('late_fee_waived')->default(false);
                $table->decimal('late_fee_posted', 14, 2)->default(0); // already journaled
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->enum('status', ['unpaid', 'partial', 'paid', 'void'])->default('unpaid')->index();

                $table->dateTime('voided_at')->nullable();
                $table->text('void_reason')->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps(6);

                $table->unique(['rental_contract_id', 'kind', 'sequence'], 'rental_invoices_period_unique');
            });
        }

        if (! Schema::hasTable('rental_payments')) {
            Schema::create('rental_payments', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('payment_number', 32)->index();
                $table->unsignedBigInteger('rental_contract_id')->nullable()->index();
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->integer('client_id')->index();
                $table->decimal('amount', 14, 2);
                $table->date('payment_date')->index();
                $table->integer('payment_method_id')->nullable();
                $table->integer('account_id')->nullable();
                $table->string('reference', 191)->nullable();
                $table->text('notes')->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_payment_allocations')) {
            Schema::create('rental_payment_allocations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_payment_id')->index();
                $table->unsignedBigInteger('rental_invoice_id')->index();
                $table->decimal('amount', 14, 2);
                $table->timestamps(6);
            });
        }

        if (Schema::hasTable('rental_settings') && ! Schema::hasColumn('rental_settings', 'invoice_lead_days')) {
            Schema::table('rental_settings', function (Blueprint $table) {
                // Issue each period's invoice this many days before it starts.
                $table->unsignedSmallInteger('invoice_lead_days')->default(7)->after('reminder_days_before');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_settings', 'invoice_lead_days')) {
            Schema::table('rental_settings', fn (Blueprint $table) => $table->dropColumn('invoice_lead_days'));
        }
        Schema::dropIfExists('rental_payment_allocations');
        Schema::dropIfExists('rental_payments');
        Schema::dropIfExists('rental_invoices');
    }
};
