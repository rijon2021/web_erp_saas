<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commission receipt records which cash/bank account the payout left from,
 * so the payout can reduce that account's balance and post a journal
 * (Dr Sales Commission Expense / Cr the account) like an Expense does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_receipts') || Schema::hasColumn('commission_receipts', 'account_id')) {
            return;
        }
        Schema::table('commission_receipts', function (Blueprint $table) {
            $table->integer('account_id')->nullable()->index()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('commission_receipts') && Schema::hasColumn('commission_receipts', 'account_id')) {
            Schema::table('commission_receipts', function (Blueprint $table) {
                $table->dropColumn('account_id');
            });
        }
    }
};
