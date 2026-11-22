<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `sale_details.date`.
 *
 * The dashboard's top-products widgets, the COGS / average-cost calculations
 * and several reports filter sale lines with whereBetween('sale_details.date'),
 * which scanned the whole table — the largest one in a busy tenant. `sales.date`
 * was covered by 2026_09_21_000003; this is the matching index for the lines.
 *
 * Adding an index rewrites the table, so on a tenant with millions of sale lines
 * run it in a maintenance window.
 */
return new class extends Migration
{
    private const INDEX = 'sale_details_date_index';

    public function up(): void
    {
        if (! Schema::hasTable('sale_details') || ! Schema::hasColumn('sale_details', 'date')) {
            return;
        }

        if (Schema::hasIndex('sale_details', self::INDEX)) {
            return;
        }

        Schema::table('sale_details', function (Blueprint $table) {
            $table->index('date', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_details') || ! Schema::hasIndex('sale_details', self::INDEX)) {
            return;
        }

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
