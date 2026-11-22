<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global master switch for the Warehouse Letterhead module
 * (System Settings -> Features). When off, invoices, receipts and the public
 * invoice page print the company block from the settings row exactly as they
 * did before, so existing tenants are untouched until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_warehouse_letterhead')) {
                $table->boolean('enable_warehouse_letterhead')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_warehouse_letterhead')) {
                $table->dropColumn('enable_warehouse_letterhead');
            }
        });
    }
};
