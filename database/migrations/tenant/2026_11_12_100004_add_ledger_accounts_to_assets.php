<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets → Journal.
 *
 * account_id          = the cash/bank account the asset was paid from
 *                       (NULL = already owned / paid outside the system,
 *                       booked against Opening Balance Equity).
 * disposal_account_id = the account sale proceeds went into on disposal.
 *
 * Postings themselves live in acc_journal_entries (App\Services\AccountingV2\AssetJournal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable();
            }
            if (! Schema::hasColumn('assets', 'disposal_account_id')) {
                $table->unsignedBigInteger('disposal_account_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            foreach (['account_id', 'disposal_account_id'] as $col) {
                if (Schema::hasColumn('assets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
