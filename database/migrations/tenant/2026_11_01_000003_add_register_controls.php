<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register controls and code numbering:
 *  - roles.max_discount_percent: the most a cashier of the role may take off a
 *    POS sale (null = no limit);
 *  - pos_settings.confirm_zero_price: ask before selling an item at 0;
 *  - settings.product_code_prefix / product_code_next: codes Stocky generates
 *    become prefix + number instead of a random 8-digit number, so they never
 *    clash with codes from another system.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'max_discount_percent')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->decimal('max_discount_percent', 5, 2)->nullable()->after('description');
            });
        }

        if (Schema::hasTable('pos_settings') && ! Schema::hasColumn('pos_settings', 'confirm_zero_price')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->boolean('confirm_zero_price')->default(false);
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                if (! Schema::hasColumn('settings', 'product_code_prefix')) {
                    $table->string('product_code_prefix', 20)->nullable();
                }
                if (! Schema::hasColumn('settings', 'product_code_next')) {
                    $table->unsignedBigInteger('product_code_next')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'max_discount_percent')) {
            Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('max_discount_percent'));
        }
        if (Schema::hasColumn('pos_settings', 'confirm_zero_price')) {
            Schema::table('pos_settings', fn (Blueprint $table) => $table->dropColumn('confirm_zero_price'));
        }
        foreach (['product_code_prefix', 'product_code_next'] as $column) {
            if (Schema::hasColumn('settings', $column)) {
                Schema::table('settings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
