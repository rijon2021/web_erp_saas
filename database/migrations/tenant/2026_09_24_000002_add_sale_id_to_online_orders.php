<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link an online order to the Sale it becomes on confirm.
 *
 * Without the link, a payment recorded later against that Sale (cash on
 * delivery, a bank transfer settled offline) could not reach the order, so
 * Online Orders kept showing "pending" after the money was in.
 *
 * Backfill: a confirmed order is matched to a non-POS sale of the same client,
 * total and date, and linked only when exactly one such sale exists and no
 * other order has claimed it. The order's payment_status is then brought in
 * line with that sale. Ambiguous orders stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'sale_id')) {
                $table->integer('sale_id')->nullable()->index('sale_id_online_orders')->after('warehouse_id');
            }
        });

        $orders = DB::table('online_orders')
            ->whereNull('sale_id')
            ->whereIn('status', ['confirmed', 'shipped', 'delivered'])
            ->get(['id', 'client_id', 'total', 'date']);

        foreach ($orders as $order) {
            $candidates = DB::table('sales')
                ->whereNull('deleted_at')
                ->where('is_pos', 0)
                ->where('client_id', $order->client_id)
                ->where('date', $order->date)
                ->whereRaw('ABS(GrandTotal - ?) < 0.01', [(float) $order->total])
                ->whereNotIn('id', DB::table('online_orders')->whereNotNull('sale_id')->select('sale_id'))
                ->limit(2)
                ->get(['id', 'payment_statut']);

            if ($candidates->count() !== 1) {
                continue;
            }

            $sale = $candidates->first();
            $update = ['sale_id' => $sale->id];
            if ($sale->payment_statut === 'paid') {
                $update['payment_status'] = 'paid';
            }

            DB::table('online_orders')->where('id', $order->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (Schema::hasColumn('online_orders', 'sale_id')) {
                $table->dropIndex('sale_id_online_orders');
                $table->dropColumn('sale_id');
            }
        });
    }
};
