<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 6: deposits, handover (check-out) and return
     * (check-in).
     *
     *  - rental_deposit_transactions: the deposit ledger of a contract
     *    (collected / refunded / applied to invoices / retained). Held =
     *    collected − refunded − applied − retained.
     *  - rental_handovers (+ _items): one record per check-out or check-in,
     *    with per-item condition and meter readings, checklist, photos and the
     *    customer's signature.
     *  - contract lines remember when they went out and came back, so a line
     *    returned early stops holding its item.
     *  - rental_payments.source = 'deposit' marks invoice payments taken from
     *    the deposit (journaled against the deposit liability, not cash).
     */
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_contracts', 'checked_out_at')) {
                $table->dateTime('checked_out_at')->nullable()->after('activated_at');
            }
            if (! Schema::hasColumn('rental_contracts', 'returned_at')) {
                $table->dateTime('returned_at')->nullable()->after('checked_out_at');
            }
        });

        Schema::table('rental_contract_items', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_contract_items', 'checked_out_at')) {
                $table->dateTime('checked_out_at')->nullable();
            }
            if (! Schema::hasColumn('rental_contract_items', 'returned_at')) {
                $table->dateTime('returned_at')->nullable()->index();
            }
        });

        if (! Schema::hasColumn('rental_payments', 'source')) {
            Schema::table('rental_payments', function (Blueprint $table) {
                $table->enum('source', ['cash', 'deposit'])->default('cash')->after('amount');
            });
        }

        if (! Schema::hasTable('rental_deposit_transactions')) {
            Schema::create('rental_deposit_transactions', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->unsignedBigInteger('rental_customer_id')->index();
                $table->enum('type', ['collected', 'refunded', 'applied', 'retained'])->index();
                $table->decimal('amount', 14, 2);
                $table->date('transaction_date');
                $table->integer('payment_method_id')->nullable();
                $table->integer('account_id')->nullable();
                $table->unsignedBigInteger('rental_payment_id')->nullable(); // for "applied"
                $table->unsignedBigInteger('rental_handover_id')->nullable();
                $table->string('reference', 191)->nullable();
                $table->text('notes')->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_handovers')) {
            Schema::create('rental_handovers', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_contract_id')->index();
                $table->enum('type', ['checkout', 'checkin'])->index();
                $table->dateTime('happened_at');
                $table->json('checklist')->nullable();   // [{label, checked}]
                $table->json('photos')->nullable();      // file names
                $table->string('signature', 191)->nullable();
                $table->string('signed_name', 191)->nullable();
                $table->text('notes')->nullable();
                $table->decimal('charges_total', 14, 2)->default(0); // check-in: everything billed
                $table->integer('user_id')->nullable();
                $table->timestamps(6);
            });
        }

        if (! Schema::hasTable('rental_handover_items')) {
            Schema::create('rental_handover_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_handover_id')->index();
                $table->unsignedBigInteger('rental_contract_item_id')->index();
                $table->unsignedBigInteger('rental_item_id')->index();
                $table->string('item_name', 191);
                $table->unsignedInteger('quantity')->default(1);
                $table->string('condition', 20)->nullable();
                $table->decimal('meter_reading', 14, 2)->nullable();
                $table->text('damage_notes')->nullable();
                $table->decimal('damage_charge', 14, 2)->default(0);
                $table->boolean('needs_maintenance')->default(false);
                $table->timestamps(6);
            });
        }

        Schema::table('rental_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_settings', 'checkout_checklist')) {
                $table->json('checkout_checklist')->nullable();
            }
            if (! Schema::hasColumn('rental_settings', 'checkin_checklist')) {
                $table->json('checkin_checklist')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_handover_items');
        Schema::dropIfExists('rental_handovers');
        Schema::dropIfExists('rental_deposit_transactions');
        foreach ([['rental_settings', ['checkout_checklist', 'checkin_checklist']], ['rental_payments', ['source']],
            ['rental_contract_items', ['checked_out_at', 'returned_at']], ['rental_contracts', ['checked_out_at', 'returned_at']]] as [$t, $cols]) {
            foreach ($cols as $col) {
                if (Schema::hasColumn($t, $col)) {
                    Schema::table($t, fn (Blueprint $table) => $table->dropColumn($col));
                }
            }
        }
    }
};
