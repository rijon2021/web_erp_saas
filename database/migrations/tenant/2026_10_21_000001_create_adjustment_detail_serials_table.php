<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * adjustment_detail_serials — which serial / IMEI units an adjustment line moved.
 *
 * action:
 *   in    — "add" of a tracked product: the unit was created in stock
 *   out   — "sub" of a tracked product: the unit was written off
 *   kit   — "add" of a combo (kit assembly): a component unit went into a kit
 *   unkit — "sub" of a combo (disassembly): a component unit came back out
 *
 * Editing or deleting the adjustment reverses these rows (SerialNumberService,
 * Adjustment section).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adjustment_detail_serials')) {
            return;
        }

        Schema::create('adjustment_detail_serials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedInteger('adjustment_id');
            $table->unsignedInteger('adjustment_detail_id');
            $table->unsignedBigInteger('product_serial_id');
            $table->string('serial_number', 191);
            $table->string('action', 10);

            $table->timestamps(6);

            $table->index('adjustment_id', 'ads_adjustment_idx');
            $table->index('adjustment_detail_id', 'ads_adjustment_detail_idx');
            $table->index('product_serial_id', 'ads_product_serial_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_detail_serials');
    }
};
