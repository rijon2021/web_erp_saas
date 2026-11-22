<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Wallet: a prepaid balance held with each supplier (advances paid
 * ahead of purchases, credit notes from purchase returns, manual
 * adjustments) that can later be spent on purchase payments.
 *
 * The tables are owner-polymorphic (owner_type/owner_id) on purpose so a
 * customer e-wallet can reuse them later; today only owner_type = 'provider'
 * is written (App\Services\SupplierWallet\SupplierWalletService).
 *
 * Every balance change writes a wallet_transactions row with balance_after,
 * so the ledger is auditable and the running balance is reproducible.
 *
 * Payment rows funded from (purchase payments, opening-balance settlements)
 * or credited to (purchase return payments) the wallet carry the id of their
 * wallet transaction, which is how deletes reverse the wallet and how the
 * accounting journal picks 1300-SUPADV instead of a cash account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('owner_type', 30)->default('provider');
                $table->integer('owner_id');
                $table->decimal('balance', 15, 2)->default(0);
                $table->string('currency', 10)->nullable();
                $table->string('status', 20)->default('active'); // active | frozen
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['owner_type', 'owner_id'], 'wallets_owner_unique');
                $table->index(['status']);
            });
        }

        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('wallet_id');
                $table->string('type', 10);   // credit | debit
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_after', 15, 2)->default(0);
                // top_up | refund | adjustment | purchase_payment | return_credit
                // | opening_balance_payment | reversal
                $table->string('source', 30)->default('adjustment');
                $table->string('reference_type', 60)->nullable();
                $table->integer('reference_id')->nullable();
                // Cash leg of a top-up / refund (accounts.id); null for non-cash moves.
                $table->integer('account_id')->nullable();
                $table->integer('payment_method_id')->nullable();
                $table->date('date')->nullable();
                $table->string('note', 500)->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps();
                $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
                $table->index(['wallet_id', 'type']);
                $table->index(['source']);
                $table->index(['date']);
            });
        }

        foreach (['payment_purchases', 'payment_purchase_returns', 'provider_opening_balance_payments'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'wallet_transaction_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['payment_purchases', 'payment_purchase_returns', 'provider_opening_balance_payments'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'wallet_transaction_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('wallet_transaction_id');
                });
            }
        }
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
