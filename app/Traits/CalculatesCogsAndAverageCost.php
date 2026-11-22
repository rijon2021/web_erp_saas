<?php

namespace App\Traits;

use App\Models\AdjustmentDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturnDetails;
use App\Models\SaleDetail;
use App\Models\SaleReturnDetails;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * Shared FIFO / Average-cost helpers for profit & COGS calculations.
 *
 * Used by both legacy reports and dashboard.
 */
trait CalculatesCogsAndAverageCost
{
    /**
     * Fast COGS using:
     *  - FIFO: purchases grouped once + pointer burn-up to start date
     *  - AVG: set-based average cost per product/variant at end date
     *
     * Received sale returns are netted out: returned units went back to stock, so
     * they are no longer a cost of the period. COGS is costed on (sold − returned).
     */
    protected function calcCogsAndAvgCostFast(string $start, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        // Respect per-user record_view permissions (same pattern as DashboardController 504–506)
        $user = Auth::user();
        $view_records = $user ? $user->hasRecordView() : false;

        $key = fn ($pid, $vid) => $pid.':'.($vid ?? 'null');

        $soldQty = function ($dateScope, $productIds = null) use ($warehouseId, $warehouseIds, $view_records, $key) {
            return SaleDetail::join('sales as s', 's.id', '=', 'sale_details.sale_id')
                ->when($productIds !== null, fn ($q) => $q->whereIn('sale_details.product_id', $productIds))
                ->where('s.statut', 'completed')
                ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId),
                    fn ($q) => $q->whereIn('s.warehouse_id', $warehouseIds))
                ->where(function ($query) use ($view_records) {
                    if (! $view_records) {
                        return $query->where('s.user_id', '=', Auth::user()->id);
                    }
                })
                ->where($dateScope)
                ->select('sale_details.product_id', 'sale_details.product_variant_id', DB::raw('SUM(sale_details.quantity) as qty'))
                ->groupBy('sale_details.product_id', 'sale_details.product_variant_id')
                ->get()
                ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
        };

        // Same scoping as sales, on the return header (return details carry no date).
        $returnedQty = function ($dateScope, $productIds = null) use ($warehouseId, $warehouseIds, $view_records, $key) {
            return SaleReturnDetails::join('sale_returns as sr', 'sr.id', '=', 'sale_return_details.sale_return_id')
                ->when($productIds !== null, fn ($q) => $q->whereIn('sale_return_details.product_id', $productIds))
                ->whereNull('sr.deleted_at')
                ->where('sr.statut', 'received')
                ->when($warehouseId, fn ($q) => $q->where('sr.warehouse_id', $warehouseId),
                    fn ($q) => $q->whereIn('sr.warehouse_id', $warehouseIds))
                ->where(function ($query) use ($view_records) {
                    if (! $view_records) {
                        return $query->where('sr.user_id', '=', Auth::user()->id);
                    }
                })
                ->where($dateScope)
                ->select('sale_return_details.product_id', 'sale_return_details.product_variant_id', DB::raw('SUM(sale_return_details.quantity) as qty'))
                ->groupBy('sale_return_details.product_id', 'sale_return_details.product_variant_id')
                ->get()
                ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
        };

        // Sold / returned qty in period per key — together their keys are the set
        // of (product, variant) pairs whose cost moved in the period.
        $salesQty = $soldQty(fn ($q) => $q->whereBetween('sale_details.date', [$start, $end]));
        $returnsQty = $returnedQty(fn ($q) => $q->whereBetween('sr.date', [$start, $end]));

        $periodKeys = $salesQty->union($returnsQty)->values();

        if ($periodKeys->isEmpty()) {
            return ['fifo' => 0.0, 'avg' => 0.0];
        }

        $productIds = $periodKeys->pluck('product_id')->unique()->values();
        $variantIds = $periodKeys->pluck('product_variant_id')->unique()->filter()->values();

        // Preload product.cost once for safe fallbacks when there are no purchases/adjustments
        $productCosts = Product::whereIn('id', $productIds)
            ->pluck('cost', 'id');

        // Variant products store their cost on the variant (products.cost is 0 for them)
        $variantCosts = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->pluck('cost', 'id');

        // Sold / returned qty before start (to burn FIFO layers). Only the products
        // moved in the period are ever read back, so only those are summed — the
        // unfiltered version aggregated the tenant's entire sales history.
        $salesBefore = $soldQty(fn ($q) => $q->where('sale_details.date', '<', $start), $productIds);
        $returnsBefore = $returnedQty(fn ($q) => $q->where('sr.date', '<', $start), $productIds);

        // Purchases (all time up to end) grouped and ordered (for FIFO). Purchases dated
        // after the period could not have supplied its sales, so they are left out.
        $purchases = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->whereNull('p.deleted_at')
            ->where('p.statut', 'received')
            ->where('p.date', '<=', $end)
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('p.warehouse_id', $warehouseIds))
            ->whereIn('purchase_details.product_id', $productIds)
            ->select([
                'purchase_details.product_id',
                'purchase_details.product_variant_id',
                'purchase_details.quantity',
                'purchase_details.cost',
                'purchase_details.purchase_id',
                'p.date',
            ])
            ->orderBy('p.date', 'asc')
            ->orderBy('p.id', 'asc')
            ->orderBy('purchase_details.id', 'asc')
            ->get()
            ->groupBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Completed purchase returns (up to end) send units back to the supplier, so they
        // shrink the layer they came from: the linked purchase's lines, or — when the
        // return is not linked to a purchase — the newest layers on hand at the return
        // date (under FIFO the newest stock is what is still on the shelf).
        $purchaseReturns = PurchaseReturnDetails::join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_details.purchase_return_id')
            ->whereNull('pr.deleted_at')
            ->whereNull('purchase_return_details.deleted_at')
            ->where('pr.statut', 'completed')
            ->where('pr.date', '<=', $end)
            ->when($warehouseId, fn ($q) => $q->where('pr.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('pr.warehouse_id', $warehouseIds))
            ->whereIn('purchase_return_details.product_id', $productIds)
            ->select([
                'purchase_return_details.product_id',
                'purchase_return_details.product_variant_id',
                'purchase_return_details.quantity',
                'pr.purchase_id',
                'pr.date',
            ])
            ->orderBy('pr.date', 'asc')
            ->orderBy('pr.id', 'asc')
            ->get()
            ->groupBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        $day = fn ($d) => substr((string) $d, 0, 10);

        foreach ($purchaseReturns as $kstr => $returns) {
            $layers = $purchases[$kstr] ?? null;
            if (! $layers) {
                continue;
            }

            foreach ($returns as $ret) {
                $left = (float) $ret->quantity;
                $linked = $ret->purchase_id ? $layers->where('purchase_id', $ret->purchase_id) : collect();
                $candidates = $linked->isNotEmpty()
                    ? $linked
                    : $layers->filter(fn ($l) => $day($l->date) <= $day($ret->date))->reverse();

                foreach ($candidates as $layer) {
                    if ($left <= 0) {
                        break;
                    }
                    $take = min(max(0.0, (float) $layer->quantity), $left);
                    $layer->quantity = (float) $layer->quantity - $take;
                    $left -= $take;
                }
            }
        }

        // Average cost per key at end date (set-based)
        $avgCost = $this->averageCostBulk($productIds->all(), $variantIds->all(), $end, $warehouseId, $warehouseIds);

        // Cost of the units at positions [$from, $to) of the FIFO layer sequence;
        // units past the last layer are valued at $avg.
        $costRange = function ($layers, float $from, float $to, float $avg): float {
            $cost = 0.0;
            $pos = 0.0;
            foreach ($layers as $layer) {
                if ($pos >= $to) {
                    break;
                }
                $q = max(0.0, (float) $layer->quantity);
                $lo = max($from, $pos);
                $hi = min($to, $pos + $q);
                if ($hi > $lo) {
                    $cost += ($hi - $lo) * (float) $layer->cost;
                }
                $pos += $q;
            }
            $overflowFrom = max($from, $pos);
            if ($to > $overflowFrom) {
                $cost += ($to - $overflowFrom) * $avg;
            }

            return $cost;
        };

        // FIFO: iterate keys once with preloaded rows
        $totalFifo = 0.0;
        $totalAvg = 0.0;

        foreach ($periodKeys as $k) {
            $kstr = $key($k->product_id, $k->product_variant_id);
            // Negative when more came back than was sold (returns of earlier sales):
            // the period is then credited the cost of those units.
            $netQty = (float) ($salesQty[$kstr]->qty ?? 0) - (float) ($returnsQty[$kstr]->qty ?? 0);
            if (abs($netQty) < 1e-9) {
                continue;
            }

            // ---- AVG ----
            $avg = (float) ($avgCost[$kstr] ?? 0);

            // If there is no cost from purchases/adjustments, safely fall back to
            // the variant cost (variant products keep cost on the variant), then product.cost
            if ($avg <= 0) {
                $fallbackCost = 0.0;
                if ($k->product_variant_id && isset($variantCosts[$k->product_variant_id])) {
                    $fallbackCost = (float) $variantCosts[$k->product_variant_id];
                }
                if ($fallbackCost <= 0 && isset($productCosts[$k->product_id])) {
                    $fallbackCost = (float) $productCosts[$k->product_id];
                }
                if ($fallbackCost > 0) {
                    $avg = $fallbackCost;
                }
            }

            $totalAvg += $avg * $netQty;

            // ---- FIFO ----
            $layers = $purchases[$kstr] ?? collect(); // list of {quantity, cost}
            if ($layers->isEmpty()) {
                // no purchases -> fallback to average (which may already include product.cost fallback)
                $totalFifo += $avg * $netQty;

                continue;
            }

            // Units consumed before start; returned units went back to stock, so
            // they no longer hold a layer.
            $burn = max(0.0, (float) ($salesBefore[$kstr]->qty ?? 0) - (float) ($returnsBefore[$kstr]->qty ?? 0));

            if ($netQty > 0) {
                $totalFifo += $costRange($layers, $burn, $burn + $netQty, $avg);
            } else {
                // Returned units are credited at the cost of the most recently consumed
                // layers; any excess beyond recorded consumption is credited at $avg.
                $lo = $burn + $netQty;
                $credit = $costRange($layers, max(0.0, $lo), $burn, $avg);
                if ($lo < 0) {
                    $credit += -$lo * $avg;
                }
                $totalFifo -= $credit;
            }
        }

        return ['fifo' => $totalFifo, 'avg' => $totalAvg];
    }

    /**
     * Revenue and parts cost from service / repair jobs delivered in the period.
     *
     * Delivery is the point of realisation for a repair job: it is where the parts
     * leave stock and where their cost is snapshotted onto the line, mirroring the way
     * a sale is realised when it is marked completed.
     *
     * @return array{revenue: float, parts_cost: float, profit: float, count: int}
     */
    protected function serviceJobTotals(string $start, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->endOfDay();

        // Jobs with no warehouse (labour-only, or created before warehouses were tracked
        // on the job) belong to no single warehouse, so they only surface unfiltered.
        $scopeWarehouse = function ($q, string $column) use ($warehouseId, $warehouseIds) {
            return $warehouseId
                ? $q->where($column, $warehouseId)
                : $q->where(fn ($w) => $w->whereIn($column, $warehouseIds)->orWhereNull($column));
        };

        $jobs = ServiceJob::whereNull('deleted_at')
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$from, $to]);
        $scopeWarehouse($jobs, 'warehouse_id');

        $revenue = (float) (clone $jobs)->sum('total_amount');
        $count = (int) (clone $jobs)->count();

        $parts = ServiceJobItem::join('service_jobs as sj', 'sj.id', '=', 'service_job_items.service_job_id')
            ->whereNull('service_job_items.deleted_at')
            ->whereNull('sj.deleted_at')
            ->where('sj.status', 'delivered')
            ->where('service_job_items.type', 'part')
            ->whereBetween('sj.delivered_at', [$from, $to]);
        $scopeWarehouse($parts, 'sj.warehouse_id');

        $partsCost = (float) $parts->sum(
            DB::raw('service_job_items.cost * service_job_items.quantity')
        );

        return [
            'revenue' => $revenue,
            'parts_cost' => $partsCost,
            'profit' => $revenue - $partsCost,
            'count' => $count,
        ];
    }

    /**
     * Set-based average cost by (product,variant) at end date.
     * AVG = (Σ purchases qty*cost − Σ purchase returns qty*cost + Σ adjustments(±qty)*product.cost)
     *       / (Σ purchases qty − Σ purchase returns qty + Σ adjustments qty)
     */
    protected function averageCostBulk(array $productIds, array $variantIds, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        $key = fn ($pid, $vid) => $pid.':'.($vid ?? 'null');

        // Purchases up to end
        $pIn = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->whereNull('p.deleted_at')
            ->where('p.statut', 'received')
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('p.warehouse_id', $warehouseIds))
            ->whereIn('purchase_details.product_id', $productIds)
            ->where('p.date', '<=', $end)
            ->select(
                'purchase_details.product_id',
                'purchase_details.product_variant_id',
                DB::raw('SUM(purchase_details.quantity) as qty'),
                DB::raw('SUM(purchase_details.quantity * purchase_details.cost) as cost')
            )
            ->groupBy('purchase_details.product_id', 'purchase_details.product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Completed purchase returns up to end, at the cost credited on the return line
        $pOut = PurchaseReturnDetails::join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_details.purchase_return_id')
            ->whereNull('pr.deleted_at')
            ->whereNull('purchase_return_details.deleted_at')
            ->where('pr.statut', 'completed')
            ->when($warehouseId, fn ($q) => $q->where('pr.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('pr.warehouse_id', $warehouseIds))
            ->whereIn('purchase_return_details.product_id', $productIds)
            ->where('pr.date', '<=', $end)
            ->select(
                'purchase_return_details.product_id',
                'purchase_return_details.product_variant_id',
                DB::raw('SUM(purchase_return_details.quantity) as qty'),
                DB::raw('SUM(purchase_return_details.quantity * purchase_return_details.cost) as cost')
            )
            ->groupBy('purchase_return_details.product_id', 'purchase_return_details.product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Adjustments up to end (valued at product.cost)
        $adj = AdjustmentDetail::join('adjustments as a', 'a.id', '=', 'adjustment_details.adjustment_id')
            ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('a.warehouse_id', $warehouseIds))
            ->whereIn('adjustment_details.product_id', $productIds)
            ->where('a.date', '<=', $end)
            ->leftJoin('products as pr', 'pr.id', '=', 'adjustment_details.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'adjustment_details.product_variant_id')
            ->select(
                'adjustment_details.product_id',
                'adjustment_details.product_variant_id',
                DB::raw("SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) as qty"),
                DB::raw("SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) * COALESCE(NULLIF(pv.cost,0), pr.cost, 0) as cost")
            )
            ->groupBy('adjustment_details.product_id', 'adjustment_details.product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Build map avg cost per key
        $avg = [];
        // unify keys from purchases/adjustments
        $keys = collect(array_unique(array_merge($pIn->keys()->all(), $adj->keys()->all())));
        foreach ($keys as $kstr) {
            $pq = (float) ($pIn[$kstr]->qty ?? 0);
            $pc = (float) ($pIn[$kstr]->cost ?? 0);
            $aq = (float) ($adj[$kstr]->qty ?? 0);
            $ac = (float) ($adj[$kstr]->cost ?? 0);
            $rq = (float) ($pOut[$kstr]->qty ?? 0);
            $rc = (float) ($pOut[$kstr]->cost ?? 0);

            $qty = $pq - $rq + $aq;
            $cost = $pc - $rc + $ac;
            $avg[$kstr] = $qty > 0 ? max(0.0, $cost / $qty) : 0.0;
        }

        return $avg;
    }
}











