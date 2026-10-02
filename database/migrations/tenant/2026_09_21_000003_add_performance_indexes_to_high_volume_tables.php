<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns every POS search and report filters on.
 *
 * `sales` carried indexes for its foreign keys only, so anything filtering by
 * date, status or reference — which is most of the reporting surface — fell back
 * to a full table scan. `products` had none on code or name, so every barcode
 * lookup at the till scanned the catalogue, and `clients` had none on phone or
 * code.
 *
 * The composites lead with `deleted_at` on purpose: almost every query pairs
 * "not deleted" with a date range or a status, and with `deleted_at` first the
 * index is ordered by the second column inside the live rows, which is exactly
 * what those scans want. A leading `deleted_at` also serves queries that filter
 * on it alone.
 *
 * Adding an index rewrites the table, so on a tenant with millions of sales this
 * migration takes real time. Run it in a maintenance window and time it against
 * a copy of your largest tenant first.
 */
return new class extends Migration
{
    /**
     * [table, index name, columns]
     */
    private array $indexes = [
        // Reports and lists: "not deleted, in this date range".
        ['sales', 'sales_deleted_at_date_index', ['deleted_at', 'date']],
        // Customer dues and sales lists: "not deleted, completed".
        ['sales', 'sales_deleted_at_statut_index', ['deleted_at', 'statut']],
        // Cash register close and POS shift reports.
        ['sales', 'sales_is_pos_warehouse_created_index', ['is_pos', 'warehouse_id', 'created_at']],
        // Reference search from the POS and the documents screens.
        ['sales', 'sales_ref_index', ['Ref']],

        // Barcode / code lookup at the till, and name search.
        ['products', 'products_code_index', ['code']],
        ['products', 'products_name_index', ['name']],

        // Customer lookup by phone or code, both used as POS search keys.
        ['clients', 'clients_phone_index', ['phone']],
        ['clients', 'clients_code_index', ['code']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $name, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Skip anything the tenant already has: these databases are not all
            // at the same point in history, and a duplicate index is an error.
            if (Schema::hasIndex($table, $name)) {
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
