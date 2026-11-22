<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text order number on sales and purchases (e.g. the CS-Cart order or
 * the supplier's invoice number), printed on invoices and sent to couriers as
 * the order reference instead of the Stocky Ref when filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'purchases'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'order_reference')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('order_reference', 100)->nullable()->after('Ref')->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sales', 'purchases'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_reference')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['order_reference']);
                    $t->dropColumn('order_reference');
                });
            }
        }
    }
};
