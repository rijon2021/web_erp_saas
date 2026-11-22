<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify: every sync type now runs in slices (a queued job, or the page that
 * started it) and is carried on from its cursor. The options it was started
 * with are kept on the run so each slice behaves the same, and the cursor
 * widens to text — a pull's cursor holds a Shopify page_info plus the position
 * within that page, which can outgrow 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $table) {
            $table->text('cursor')->nullable()->change();

            if (! Schema::hasColumn('shopify_sync_runs', 'options')) {
                $table->json('options')->nullable()->after('cursor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_sync_runs', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_sync_runs', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
};
