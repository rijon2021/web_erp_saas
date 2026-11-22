<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Wallet: a stored-value balance per customer (top-ups, refunds
 * credited to the wallet, gift cards, manual corrections) spent on sale
 * payments, POS tenders and storefront orders.
 *
 * Balances live in the owner-polymorphic `wallets` / `wallet_transactions`
 * tables created by the Supplier Wallet migration (2026_11_07_000001), with
 * owner_type = 'client'. This migration adds what only customers need:
 *   - wallet_withdrawals: cash-out requests from the storefront
 *   - gift_cards: codes the business issues that credit a wallet on redeem
 *   - wallet_transaction_id on the payment rows funded from (sale payments,
 *     opening-balance settlements) or credited to (sale return payments) the
 *     wallet, so deletes reverse the wallet and the journal settles against
 *     2310-CUSTWAL instead of a cash account.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Normally already there from the Supplier Wallet migration; created
        // here too so the customer wallet never depends on that one having run.
        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('owner_type', 30)->default('provider');
                $table->integer('owner_id');
                $table->decimal('balance', 15, 2)->default(0);
                $table->string('currency', 10)->nullable();
                $table->string('status', 20)->default('active');
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
                $table->string('type', 10);
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->string('source', 30)->default('adjustment');
                $table->string('reference_type', 60)->nullable();
                $table->integer('reference_id')->nullable();
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

        if (! Schema::hasTable('wallet_withdrawals')) {
            Schema::create('wallet_withdrawals', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('wallet_id');
                $table->decimal('amount', 15, 2);
                // pending -> approved (wallet debited) -> paid (cash left the account)
                // pending|approved -> rejected (an approved one gets its debit reversed)
                $table->string('status', 20)->default('pending');
                $table->string('method', 60)->nullable();        // what the customer asked for
                $table->string('destination', 190)->nullable();  // account no. / details from the customer
                $table->string('note', 255)->nullable();         // admin note
                $table->unsignedBigInteger('wallet_transaction_id')->nullable(); // the debit made on approval
                $table->integer('account_id')->nullable();         // cash account paid from
                $table->integer('payment_method_id')->nullable();
                $table->integer('processed_by')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
                $table->index(['status']);
            });
        }

        if (! Schema::hasTable('gift_cards')) {
            Schema::create('gift_cards', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('type', 30)->default('gift_card'); // gift_card | voucher | store_credit
                $table->string('code', 60)->unique();
                $table->string('name', 120)->nullable();
                $table->decimal('amount', 15, 2)->default(0);   // face value
                $table->decimal('balance', 15, 2)->default(0);  // still redeemable
                $table->string('status', 20)->default('active'); // active | redeemed | disabled | expired
                $table->timestamp('expires_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->integer('created_by')->nullable();
                $table->integer('redeemed_by')->nullable();      // clients.id
                $table->timestamp('redeemed_at')->nullable();
                $table->unsignedBigInteger('wallet_transaction_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status']);
                $table->index(['type']);
            });
        }

        foreach (['payment_sales', 'payment_sale_returns', 'client_opening_balance_payments'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'wallet_transaction_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['payment_sales', 'payment_sale_returns', 'client_opening_balance_payments'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'wallet_transaction_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('wallet_transaction_id');
                });
            }
        }
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('wallet_withdrawals');
        // wallets / wallet_transactions belong to the Supplier Wallet migration
        // too; only drop the customer rows, never the shared tables.
        if (Schema::hasTable('wallets')) {
            \Illuminate\Support\Facades\DB::table('wallets')->where('owner_type', 'client')->delete();
        }
    }
};
