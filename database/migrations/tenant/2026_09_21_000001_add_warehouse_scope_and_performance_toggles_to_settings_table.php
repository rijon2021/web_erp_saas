<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two admin switches on Settings → Features.
 *
 * restrict_master_data_by_warehouse: whether a user assigned to specific
 * warehouses also sees only the products, customers, suppliers, brands and
 * categories connected to those warehouses. Transaction documents (sales,
 * purchases, returns, transfers) are ALWAYS restricted and are not affected by
 * this flag — that is access control, not a preference. Master data is the
 * genuine business choice: some companies share one customer book across every
 * branch, others treat each branch as its own. Defaults to FALSE so existing
 * installs see no change until an admin opts in.
 *
 * customer_performance_enabled: shows the performance score and rating on the
 * customer page. Defaults to TRUE because it is additive and harmless; an admin
 * who would rather staff did not see a customer rating can switch it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'restrict_master_data_by_warehouse')) {
                $table->boolean('restrict_master_data_by_warehouse')->default(false);
            }
            if (! Schema::hasColumn('settings', 'customer_performance_enabled')) {
                $table->boolean('customer_performance_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['restrict_master_data_by_warehouse', 'customer_performance_enabled'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
