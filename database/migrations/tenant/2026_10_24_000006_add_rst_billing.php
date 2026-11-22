<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 6: billing.
 *
 * Every payment becomes a normal POS sale. A bill paid in one go (or split
 * equally) is one sale; "split by item" makes one sale per guest, so each
 * dish remembers the sale that paid it (rst_order_items.sale_id) and the
 * order keeps a list of its payments (rst_order_payments). The service charge
 * and tips are booked on two service products the module creates on first
 * use (rst_settings.service_product_id / tip_product_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rst_order_items') && ! Schema::hasColumn('rst_order_items', 'sale_id')) {
            Schema::table('rst_order_items', function (Blueprint $table) {
                $table->integer('sale_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('rst_order_payments')) {
            Schema::create('rst_order_payments', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('order_id')->index();
                $table->integer('sale_id')->index();
                $table->string('split', 10)->default('full'); // full | equal | items
                $table->unsignedTinyInteger('shares')->nullable();
                $table->decimal('amount', 14, 2)->default(0);          // sale grand total
                $table->decimal('service_charge', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tip', 14, 2)->default(0);
                $table->decimal('rounding', 14, 2)->default(0);
                $table->integer('paid_by')->nullable();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('rst_orders')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('rst_settings', 'service_product_id')) {
                    $table->integer('service_product_id')->nullable();
                }
                if (! Schema::hasColumn('rst_settings', 'tip_product_id')) {
                    $table->integer('tip_product_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                foreach (['service_product_id', 'tip_product_id'] as $c) {
                    if (Schema::hasColumn('rst_settings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        Schema::dropIfExists('rst_order_payments');
        if (Schema::hasTable('rst_order_items') && Schema::hasColumn('rst_order_items', 'sale_id')) {
            Schema::table('rst_order_items', function (Blueprint $table) {
                $table->dropIndex(['sale_id']);
                $table->dropColumn('sale_id');
            });
        }
    }
};
