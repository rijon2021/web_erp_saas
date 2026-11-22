<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transfer_details.batch_picks — the source batches the user picked for a
 * batch-tracked line, as JSON [{source_batch_id, qty, unit_cost}].
 *
 * New transfers start pending approval, before any stock moves, so the picks
 * have to be kept until approval applies them to product_batches (the
 * transfer_detail_batches pivot only records picks that have been applied).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transfer_details', 'batch_picks')) {
            Schema::table('transfer_details', function (Blueprint $table) {
                $table->text('batch_picks')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transfer_details', 'batch_picks')) {
            Schema::table('transfer_details', function (Blueprint $table) {
                $table->dropColumn('batch_picks');
            });
        }
    }
};
