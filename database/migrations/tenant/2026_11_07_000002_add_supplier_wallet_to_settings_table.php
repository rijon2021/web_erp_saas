<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Features → Supplier Wallet switches. The feature also
 * needs the `supplier_wallet` plan feature; the tenant switch lets a merchant
 * on such a plan keep it off. allow_negative lets a wallet be overdrawn
 * (the supplier extends credit on the advance) instead of refusing the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'supplier_wallet_enabled')) {
                $table->boolean('supplier_wallet_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'supplier_wallet_allow_negative')) {
                $table->boolean('supplier_wallet_allow_negative')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach (['supplier_wallet_enabled', 'supplier_wallet_allow_negative'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
