<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management (plan feature `restaurant`), phase 1: the module's
 * single settings row. Later phases add areas/tables, orders, add-ons…
 * under the same `rst_` prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rst_settings')) {
            return;
        }
        Schema::create('rst_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('order_prefix', 16)->default('RO');
            $table->boolean('dine_in_enabled')->default(true);
            $table->boolean('takeaway_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(false);
            $table->boolean('require_guest_count')->default(true);
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            $table->string('service_charge_scope', 20)->default('dine_in'); // dine_in | all
            $table->boolean('tips_enabled')->default(true);
            $table->json('tip_presets')->nullable();
            $table->decimal('bill_rounding', 6, 2)->default(0); // 0 = no rounding
            $table->decimal('max_discount_percent', 5, 2)->default(100);
            $table->integer('default_warehouse_id')->nullable();
            $table->unsignedSmallInteger('late_ticket_minutes')->default(20);
            $table->string('after_payment_table_status', 20)->default('cleaning'); // cleaning | free
            $table->string('kitchen_online_orders', 20)->default('on_accept'); // off | on_place | on_accept
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rst_settings');
    }
};
