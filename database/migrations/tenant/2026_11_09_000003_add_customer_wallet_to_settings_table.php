<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Wallet settings. The feature also needs the `customer_wallet`
 * plan feature; the tenant switch lets a merchant on such a plan keep it off.
 *
 *  - enabled / allow_negative: Settings > Features
 *  - checkout_enabled: shoppers may pay storefront orders from the wallet
 *  - refund_destination: where approved online-order refunds go
 *    ('original' = the way they paid, 'wallet' = always the wallet; orders
 *    paid from the wallet are always refunded to it)
 *  - withdrawal_enabled / min_withdrawal: storefront cash-out requests
 *  - payment_method_id: the "Customer Wallet" tender, created on first enable
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'customer_wallet_enabled')) {
                $table->boolean('customer_wallet_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_allow_negative')) {
                $table->boolean('customer_wallet_allow_negative')->default(false);
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_checkout_enabled')) {
                $table->boolean('customer_wallet_checkout_enabled')->default(true);
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_refund_destination')) {
                $table->string('customer_wallet_refund_destination', 20)->default('original');
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_withdrawal_enabled')) {
                $table->boolean('customer_wallet_withdrawal_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_min_withdrawal')) {
                $table->decimal('customer_wallet_min_withdrawal', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('settings', 'customer_wallet_payment_method_id')) {
                $table->integer('customer_wallet_payment_method_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'customer_wallet_enabled', 'customer_wallet_allow_negative', 'customer_wallet_checkout_enabled',
                'customer_wallet_refund_destination', 'customer_wallet_withdrawal_enabled',
                'customer_wallet_min_withdrawal', 'customer_wallet_payment_method_id',
            ] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
