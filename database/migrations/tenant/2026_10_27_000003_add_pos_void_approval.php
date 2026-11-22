<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS manager approval for removing items (App\Services\Pos\PosApprovalPin):
 *  - pos_settings.require_void_approval: cashiers without the pos_void_items
 *    permission need a manager PIN to remove a line, lower a quantity or clear
 *    the cart.
 *  - users.pos_pin_hash: the manager's PIN, hashed like a password (the till
 *    asks who approves, then their PIN).
 *  - pos_voids: one row per removed line — what, how much, who asked, who approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_settings') && ! Schema::hasColumn('pos_settings', 'require_void_approval')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->boolean('require_void_approval')->default(false);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'pos_pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pos_pin_hash', 255)->nullable();
            });
        }

        if (! Schema::hasTable('pos_voids')) {
            Schema::create('pos_voids', function (Blueprint $table) {
                $table->id();
                // remove_line | decrease_qty | clear_cart
                $table->string('action', 20);
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->string('product_name', 192);
                $table->string('product_code', 192)->nullable();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('unit_price', 15, 4)->default(0);
                $table->decimal('amount', 15, 4)->default(0);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedBigInteger('cashier_id')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                // pin = a manager typed their PIN; self = the user may remove items themselves
                $table->string('approval_method', 10)->default('pin');
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->index('created_at');
                $table->index('cashier_id');
                $table->index('approved_by');
                $table->index('warehouse_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_voids');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'pos_pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_pin_hash');
            });
        }

        if (Schema::hasTable('pos_settings') && Schema::hasColumn('pos_settings', 'require_void_approval')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->dropColumn('require_void_approval');
            });
        }
    }
};
