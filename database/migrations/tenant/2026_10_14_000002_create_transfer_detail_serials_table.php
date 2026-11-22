<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transfer_detail_serials — which serial / IMEI units travel on a transfer line.
 *
 * Rows are written when the transfer is saved (even while it is pending
 * approval) and applied to product_serials only once the transfer actually
 * moves stock: approved + completed moves the unit to the destination
 * warehouse, approved + sent holds it as reserved (in transit) at the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transfer_detail_serials')) {
            return;
        }

        Schema::create('transfer_detail_serials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedInteger('transfer_id');
            $table->unsignedInteger('transfer_detail_id');
            $table->unsignedBigInteger('product_serial_id');
            $table->string('serial_number', 191);

            $table->timestamps(6);

            $table->index('transfer_id', 'tds_transfer_idx');
            $table->index('transfer_detail_id', 'tds_transfer_detail_idx');
            $table->index('product_serial_id', 'tds_product_serial_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_detail_serials');
    }
};
