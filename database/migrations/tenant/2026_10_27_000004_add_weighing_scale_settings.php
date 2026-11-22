<?php

use App\Services\Pos\ScaleBarcode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weighing-scale barcodes (System Settings > Weighing scale):
 *  - pos_settings.scale_barcode_enabled / scale_barcode_formats: the label
 *    layouts the counter scales print (prefix, item code, value digits).
 *  - products.scale_plu: the item code the scale prints for a weighed product.
 *
 * Scale decoding used to be hard-coded (first 7 digits = product code, next 5
 * = grams). Tenants that already exist keep exactly that through the "legacy"
 * format, switched on; new tenants start with it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_settings')) {
            if (! Schema::hasColumn('pos_settings', 'scale_barcode_enabled')) {
                Schema::table('pos_settings', function (Blueprint $table) {
                    $table->boolean('scale_barcode_enabled')->default(false);
                });
            }
            if (! Schema::hasColumn('pos_settings', 'scale_barcode_formats')) {
                Schema::table('pos_settings', function (Blueprint $table) {
                    $table->json('scale_barcode_formats')->nullable();
                });

                // Only rows that exist now (an existing tenant) — a new tenant's
                // row is seeded after the migrations and keeps the defaults.
                DB::table('pos_settings')->update([
                    'scale_barcode_enabled' => true,
                    'scale_barcode_formats' => json_encode([ScaleBarcode::legacyFormat()]),
                ]);
            }
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'scale_plu')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('scale_plu', 16)->nullable();
                $table->index('scale_plu');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'scale_plu')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['scale_plu']);
                $table->dropColumn('scale_plu');
            });
        }

        if (Schema::hasTable('pos_settings')) {
            foreach (['scale_barcode_formats', 'scale_barcode_enabled'] as $column) {
                if (Schema::hasColumn('pos_settings', $column)) {
                    Schema::table('pos_settings', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
