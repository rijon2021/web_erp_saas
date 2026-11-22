<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings → Features → "Edit Selling Price on Purchase".
 *
 * When the switch is on, every purchase line carries an optional selling
 * price next to its cost. A value typed there replaces the product's (or the
 * variant's) selling price when the purchase is saved; a blank line leaves
 * the product alone. The entered figure is kept on the purchase detail row
 * (purchase_details.price, base currency, NULL = not set) so the document
 * still shows what was decided at receiving time. Editing or deleting the
 * purchase later never reverts a price: a price is a decision, not stock.
 *
 * Sibling switch "Update Product Cost on Purchase"
 * (settings.purchase_update_product_cost): saving a RECEIVED purchase sets
 * each line's product / variant cost to the line's net unit cost (after the
 * line discount, tax stripped when entered inclusive) converted to the base
 * unit - last cost, as stocky's landed-cost checkbox does. Nothing is
 * stored for it on the detail row: the purchase line already is the cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'purchase_update_selling_price')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('purchase_update_selling_price')->default(false);
            });
        }

        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'purchase_update_product_cost')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('purchase_update_product_cost')->default(false);
            });
        }

        if (Schema::hasTable('purchase_details') && ! Schema::hasColumn('purchase_details', 'price')) {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->decimal('price', 15, 4)->nullable()->after('cost');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'purchase_update_selling_price')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('purchase_update_selling_price');
            });
        }

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'purchase_update_product_cost')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('purchase_update_product_cost');
            });
        }

        if (Schema::hasTable('purchase_details') && Schema::hasColumn('purchase_details', 'price')) {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }
};
