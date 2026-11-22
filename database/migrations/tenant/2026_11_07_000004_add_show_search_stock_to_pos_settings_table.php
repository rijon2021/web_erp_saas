<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → POS → "Show Stock in Search Results": the product search dropdown
 * on the POS screen shows each hit's available quantity (sale unit) next to
 * its code and name. Separate from show_stock_quantity, which governs the
 * product tiles, so a shop can show stock in one place and not the other.
 * Default on: the figure is informational and the client asked to see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_settings') || Schema::hasColumn('pos_settings', 'show_search_stock')) {
            return;
        }

        Schema::table('pos_settings', function (Blueprint $table) {
            $table->boolean('show_search_stock')->default(true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_settings') || ! Schema::hasColumn('pos_settings', 'show_search_stock')) {
            return;
        }

        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn('show_search_stock');
        });
    }
};
