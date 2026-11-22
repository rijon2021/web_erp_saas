<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * damage_detail_serials — which serial / IMEI units a damage line took out of
 * stock. A unit becomes `damaged`; from_status (available, or in_kit for a
 * kit's component) is what editing or deleting the damage restores
 * (SerialNumberService, Damage section).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('damage_detail_serials')) {
            return;
        }

        Schema::create('damage_detail_serials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->unsignedInteger('damage_id');
            $table->unsignedInteger('damage_detail_id');
            $table->unsignedBigInteger('product_serial_id');
            $table->string('serial_number', 191);
            $table->string('from_status', 20);

            $table->timestamps(6);

            $table->index('damage_id', 'dds_damage_idx');
            $table->index('damage_detail_id', 'dds_damage_detail_idx');
            $table->index('product_serial_id', 'dds_product_serial_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_detail_serials');
    }
};
