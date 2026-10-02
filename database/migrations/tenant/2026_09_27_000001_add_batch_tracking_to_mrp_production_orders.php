<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch & expiry on manufactured goods.
 *
 * A production order can carry a batch number, a production date and an
 * expiry date. On completion the finished goods are received into stock as a
 * product_batches row carrying those values, so the batch prints on the label
 * and is traceable through sales and the batch reports. Raw materials that are
 * batch-tracked are consumed FEFO on release and the consumption is recorded
 * per batch, so a finished batch can be traced back to the material batches
 * that went into it.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('mrp_production_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('mrp_production_orders', 'batch_no')) {
                $table->string('batch_no', 100)->nullable()->after('qty_scrapped');
            }
            if (! Schema::hasColumn('mrp_production_orders', 'production_date')) {
                $table->date('production_date')->nullable()->after('batch_no');
            }
            if (! Schema::hasColumn('mrp_production_orders', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('production_date');
            }
            // The product_batches row the finished goods were received into.
            if (! Schema::hasColumn('mrp_production_orders', 'product_batch_id')) {
                $table->unsignedBigInteger('product_batch_id')->nullable()->after('expiry_date');
            }
        });

        // Indexes are added separately so a re-run after a partial failure does
        // not trip over ones that already exist.
        try {
            Schema::table('mrp_production_orders', function (Blueprint $table) {
                $table->index('batch_no', 'mrp_po_batch_no_idx');
                $table->index('product_batch_id', 'mrp_po_product_batch_idx');
            });
        } catch (Throwable $e) {
            // already indexed
        }

        // Which raw-material batches an order consumed, and how much of each.
        if (! Schema::hasTable('mrp_production_order_material_batches')) {
            Schema::create('mrp_production_order_material_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('production_order_material_id');
                $table->unsignedBigInteger('product_batch_id');
                $table->double('qty', 15, 4)->default(0);
                $table->double('unit_cost', 15, 4)->nullable();
                $table->timestamps();

                $table->index('production_order_material_id', 'mrp_pomb_material_idx');
                $table->index('product_batch_id', 'mrp_pomb_batch_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('mrp_production_order_material_batches');

        Schema::table('mrp_production_orders', function (Blueprint $table) {
            try { $table->dropIndex('mrp_po_batch_no_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('mrp_po_product_batch_idx'); } catch (\Throwable $e) {}
            foreach (['batch_no', 'production_date', 'expiry_date', 'product_batch_id'] as $col) {
                if (Schema::hasColumn('mrp_production_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
