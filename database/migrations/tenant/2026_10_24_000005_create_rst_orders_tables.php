<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 4: orders taken by waiters.
 *
 * rst_orders           one open order per seated table (dine-in), or a
 *                      takeaway / delivery order; becomes a sale at billing (phase 6)
 * rst_order_items      dishes with their add-ons (priced snapshot), seat, course,
 *                      kitchen status; voided lines stay for the record
 * rst_kitchen_tickets  each "send to kitchen" batch (KOT) — the kitchen display
 *                      (phase 5) reads these
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rst_orders')) {
            Schema::create('rst_orders', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('ref', 32)->unique();
                $table->string('type', 12)->default('dine_in'); // dine_in | takeaway | delivery
                $table->string('status', 12)->default('open');  // open | paid | cancelled
                $table->unsignedInteger('table_id')->nullable()->index();
                $table->unsignedSmallInteger('guests')->nullable();
                $table->integer('waiter_id')->nullable()->index();
                $table->integer('warehouse_id')->nullable()->index();
                $table->integer('client_id')->nullable();
                $table->string('customer_name', 120)->nullable();
                $table->string('customer_phone', 40)->nullable();
                $table->text('delivery_address')->nullable();
                $table->string('note', 500)->nullable();
                $table->decimal('subtotal', 14, 2)->default(0); // active lines, before tax / service / discount
                $table->integer('sale_id')->nullable()->index();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->integer('cancelled_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'type']);
            });
        }

        if (! Schema::hasTable('rst_kitchen_tickets')) {
            Schema::create('rst_kitchen_tickets', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('order_id')->index();
                $table->string('ref', 32);
                $table->unsignedTinyInteger('course')->nullable();
                $table->string('status', 12)->default('pending'); // pending | preparing | ready | served | void
                $table->integer('sent_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('served_at')->nullable();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('rst_orders')->cascadeOnDelete();
                $table->index(['status']);
            });
        }

        if (! Schema::hasTable('rst_order_items')) {
            Schema::create('rst_order_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('order_id')->index();
                $table->integer('product_id');
                $table->integer('product_variant_id')->nullable();
                $table->string('name', 191);
                $table->decimal('qty', 12, 3)->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);   // dish / variant price
                $table->decimal('addons_price', 14, 2)->default(0); // per unit
                $table->decimal('line_total', 14, 2)->default(0);   // qty × (unit + add-ons)
                $table->json('addons')->nullable();                  // priced snapshot from MenuService::priceSelection
                $table->string('addons_label', 500)->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedTinyInteger('seat')->nullable();
                $table->unsignedTinyInteger('course')->default(1);
                $table->boolean('hold')->default(false);             // wait until the course is fired
                $table->string('status', 12)->default('new');        // new | sent | preparing | ready | served | void
                $table->unsignedInteger('station_id')->nullable();
                $table->unsignedInteger('ticket_id')->nullable()->index();
                $table->integer('added_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->integer('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('rst_orders')->cascadeOnDelete();
                $table->index(['order_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rst_order_items');
        Schema::dropIfExists('rst_kitchen_tickets');
        Schema::dropIfExists('rst_orders');
    }
};
