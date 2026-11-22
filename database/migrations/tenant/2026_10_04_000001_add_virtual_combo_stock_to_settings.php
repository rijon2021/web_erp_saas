<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual combo stock (Settings > Features): a combo's availability comes
 * from its component products instead of its own stock row, and selling it
 * takes the components.
 *
 * On by default — new tenants get it from the column default. An existing
 * tenant is switched on only when nothing depends on the old assemble-by-
 * adjustment behaviour, the same rule the switch's confirmation warns about
 * (ComboStockService::switchRiskReason): no completed sale or received
 * sales return carries a combo, and no combo holds assembled stock. Those
 * tenants keep it off, since their past documents must be reversed the way
 * they were made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'virtual_combo_stock')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('virtual_combo_stock')->default(1);
        });

        if ($this->hasKitHistory()) {
            DB::table('settings')->update(['virtual_combo_stock' => 0]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'virtual_combo_stock')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('virtual_combo_stock');
            });
        }
    }

    /** Combo sales, combo returns or assembled combo stock already exist. */
    private function hasKitHistory(): bool
    {
        $combo = fn ($q, string $column) => $q->join('products', 'products.id', '=', $column)
            ->where('products.type', 'is_combo');

        $sold = $combo(DB::table('sale_details'), 'sale_details.product_id')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->exists();

        $returned = Schema::hasTable('sale_returns') && $combo(DB::table('sale_return_details'), 'sale_return_details.product_id')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_details.sale_return_id')
            ->whereNull('sale_returns.deleted_at')
            ->where('sale_returns.statut', 'received')
            ->exists();

        $assembled = $combo(DB::table('product_warehouse'), 'product_warehouse.product_id')
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.qte', '!=', 0)
            ->exists();

        return $sold || $returned || $assembled;
    }
};
