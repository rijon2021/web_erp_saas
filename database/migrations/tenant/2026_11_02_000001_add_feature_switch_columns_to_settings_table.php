<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Features switches that the form already sent but the
 * settings table never stored, so they reset on every reload:
 *
 *  - product_image_resize / product_image_max_size: downscale uploaded product
 *    images (defaults reproduce the previously hard-coded 800x800 behaviour).
 *  - enable_pos_salesperson_switch: "Change Salesperson During Checkout" — the
 *    POS / sale form show a salesperson picker whose choice is stored in
 *    sales.seller_id while user_id stays the cashier.
 *  - export_settings: JSON blob of global export defaults (scope, totals,
 *    pdf_orientation, filename_date, pdf_meta); null = built-in defaults in
 *    resources/src/lib/exporters.js.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'product_image_resize')) {
                $table->boolean('product_image_resize')->default(true);
            }
            if (! Schema::hasColumn('settings', 'product_image_max_size')) {
                $table->unsignedInteger('product_image_max_size')->default(800);
            }
            if (! Schema::hasColumn('settings', 'enable_pos_salesperson_switch')) {
                $table->boolean('enable_pos_salesperson_switch')->default(false);
            }
            if (! Schema::hasColumn('settings', 'export_settings')) {
                $table->longText('export_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['product_image_resize', 'product_image_max_size', 'enable_pos_salesperson_switch', 'export_settings'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
