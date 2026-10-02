<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which payment methods represent physical cash in the drawer.
 *
 * The cash register close calculation needs to tell notes and coins apart from
 * card, transfer and credit tenders: only cash belongs in the expected-drawer
 * figure. Method ids can't be trusted for this — merchants rename the defaults
 * and add their own ("Caisse", "Efectivo", "Cash on delivery") — so the tenant
 * says which of its methods are cash and Settings → Payment Methods exposes the
 * flag.
 *
 * Defaults to false, then flips on for the seeded Cash method (id 2) and for
 * any method the tenant renamed but still calls cash, so existing installs
 * reconcile correctly without being touched by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_methods', 'is_cash')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->boolean('is_cash')->default(false)->after('name');
            });
        }

        DB::table('payment_methods')
            ->where(function ($q) {
                $q->where('id', 2)->orWhereRaw('LOWER(TRIM(name)) = ?', ['cash']);
            })
            ->update(['is_cash' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'is_cash')) {
                $table->dropColumn('is_cash');
            }
        });
    }
};
