<?php

namespace App\Listeners\Webhooks;

use App\Events\SaleCreated;
use App\Services\ComboStockService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fires a `product.low_stock` webhook event for each product a new sale
 * pushed to (or below) its stock alert threshold, in the sale's warehouse.
 * With virtual combo stock, a combo line checks its components instead.
 *
 * Runs after the sale's transaction commits (details and stock decrements are
 * written after the sale row itself), so quantities are read post-decrement.
 */
class CheckLowStock implements ShouldQueue
{
    /** Queue with the other webhook work; never blocks the request. */
    public $queue = 'webhooks';

    /** Only run once the originating transaction is committed. */
    public $afterCommit = true;

    public function __construct(protected WebhookDispatcher $dispatcher)
    {
    }

    public function handle(SaleCreated $event): void
    {
        try {
            $sale = $event->sale;

            // Products the sale moved: its lines, plus — with virtual combo
            // stock — the components a combo line took instead of its own row.
            $productIds = DB::table('sale_details')->where('sale_id', $sale->id)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            if (ComboStockService::enabled()) {
                foreach (app(ComboStockService::class)->recipes($productIds) as $recipe) {
                    $productIds = array_merge($productIds, array_keys($recipe));
                }
            }

            $rows = DB::table('products')
                ->join('product_warehouse', function ($join) use ($sale) {
                    $join->on('product_warehouse.product_id', '=', 'products.id')
                        ->where('product_warehouse.warehouse_id', '=', $sale->warehouse_id)
                        ->whereNull('product_warehouse.deleted_at');
                })
                ->whereIn('products.id', array_values(array_unique($productIds)))
                ->whereNull('products.deleted_at')
                ->where('products.stock_alert', '>', 0)
                ->whereColumn('product_warehouse.qte', '<=', 'products.stock_alert')
                ->tap(fn ($q) => ComboStockService::excludeVirtualCombos($q))
                ->groupBy('products.id', 'products.name', 'products.code', 'products.stock_alert', 'product_warehouse.warehouse_id')
                ->selectRaw('products.id, products.name, products.code, products.stock_alert, product_warehouse.warehouse_id, MIN(product_warehouse.qte) as qte')
                ->get();

            foreach ($rows as $row) {
                $this->dispatcher->dispatch('product.low_stock', [
                    'product_id' => (int) $row->id,
                    'name' => $row->name,
                    'code' => $row->code,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'quantity' => (float) $row->qte,
                    'stock_alert' => (float) $row->stock_alert,
                    'sale_id' => (int) $sale->id,
                ]);
            }
        } catch (Throwable $e) {
            // Never break (or retry into) the originating business flow.
            report($e);
        }
    }
}
