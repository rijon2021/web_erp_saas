<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date indexes for the rest of the transaction tables, and composite lookups
 * for product_warehouse.
 *
 * 2026_09_21_000003 indexed `sales`; purchases, returns, expenses, payments,
 * adjustments, transfers and deposits still carried foreign-key indexes only, so
 * every dashboard widget and report that asks "not deleted, in this date range"
 * scanned them whole. Same shape as that migration: `deleted_at` first, then
 * `date`.
 *
 * product_warehouse is touched on every sale, purchase, return, adjustment and
 * transfer by (product_id, warehouse_id, product_variant_id), and polled by
 * every POS terminal by (warehouse_id, updated_at) for the stock delta. It only
 * had single-column indexes, so MySQL picked one and filtered the rest row by
 * row.
 *
 * Adding an index rewrites the table, so on a large tenant run it in a
 * maintenance window.
 */
return new class extends Migration
{
    /**
     * [table, index name, columns]
     */
    private array $indexes = [
        ['purchases', 'purchases_deleted_at_date_index', ['deleted_at', 'date']],
        ['sale_returns', 'sale_returns_deleted_at_date_index', ['deleted_at', 'date']],
        ['purchase_returns', 'purchase_returns_deleted_at_date_index', ['deleted_at', 'date']],
        ['expenses', 'expenses_deleted_at_date_index', ['deleted_at', 'date']],
        ['deposits', 'deposits_deleted_at_date_index', ['deleted_at', 'date']],
        ['adjustments', 'adjustments_deleted_at_date_index', ['deleted_at', 'date']],
        ['transfers', 'transfers_deleted_at_date_index', ['deleted_at', 'date']],
        ['payment_sales', 'payment_sales_deleted_at_date_index', ['deleted_at', 'date']],
        ['payment_purchases', 'payment_purchases_deleted_at_date_index', ['deleted_at', 'date']],
        ['payment_sale_returns', 'payment_sale_returns_deleted_at_date_index', ['deleted_at', 'date']],
        ['payment_purchase_returns', 'payment_purchase_returns_deleted_at_date_index', ['deleted_at', 'date']],

        // Stock row lookup on every stock movement.
        ['product_warehouse', 'product_warehouse_product_warehouse_variant_index', ['product_id', 'warehouse_id', 'product_variant_id']],
        // POS stock delta: "rows of this warehouse changed since T".
        ['product_warehouse', 'product_warehouse_warehouse_updated_at_index', ['warehouse_id', 'updated_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }
};
