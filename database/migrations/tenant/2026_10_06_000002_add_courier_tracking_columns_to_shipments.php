<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration (phase 2): returned-parcel review, the payment /
 * expense / sale return each shipment produced (so nothing is recorded
 * twice), and status-sync diagnostics.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'return_status', 'return_note', 'return_handled_at', 'return_handled_by', 'sale_return_id',
        'cod_payment_id', 'charges_expense_id', 'sync_error', 'sync_failures',
    ];

    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'return_status')) {
                // pending (waiting for review) | restocked | dismissed
                $table->string('return_status', 20)->nullable()->index();
                $table->string('return_note', 500)->nullable();
                $table->timestamp('return_handled_at')->nullable();
                $table->integer('return_handled_by')->nullable();
                $table->integer('sale_return_id')->nullable();
                $table->integer('cod_payment_id')->nullable();
                $table->integer('charges_expense_id')->nullable();
                $table->string('sync_error', 500)->nullable();
                $table->unsignedSmallInteger('sync_failures')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
