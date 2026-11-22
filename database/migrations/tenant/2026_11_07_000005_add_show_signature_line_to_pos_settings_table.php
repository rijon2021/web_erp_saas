<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → POS Receipt → "Show Signature Line": prints a blank customer
 * signature line under the receipt, whatever layout is selected. Default off
 * so existing receipts print unchanged. (Layout 6 "Tabular" ships alongside
 * this but needs no column: receipt_layout already stores the number.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_settings') || Schema::hasColumn('pos_settings', 'show_signature_line')) {
            return;
        }

        Schema::table('pos_settings', function (Blueprint $table) {
            $table->boolean('show_signature_line')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_settings') || ! Schema::hasColumn('pos_settings', 'show_signature_line')) {
            return;
        }

        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn('show_signature_line');
        });
    }
};
