<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Product;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Traits\CalculatesCogsAndAverageCost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ReportQuestionService
{
    use CalculatesCogsAndAverageCost;

    /**
     * Execute daily sales summary report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @return array
     */
    public function dailySalesSummary(string $dateFrom, string $dateTo, ?int $warehouseId = null): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        // Get user's accessible warehouses
        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);

        // Build query
        $query = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereBetween('date', [$dateFrom, $dateTo]);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        } else {
            $query->whereIn('warehouse_id', $warehouseIds);
        }

        if (!$viewRecords) {
            $query->where('user_id', $user->id);
        }

        // Get aggregates
        $sales = $query->get();

        $transactions = $sales->count();
        $revenue = $sales->sum('GrandTotal');
        $tax = $sales->sum('TaxNet');
        $discount = $sales->sum('discount');

        // Calculate profit using COGS
        $cogsPack = $this->calcCogsAndAvgCostFast($dateFrom, $dateTo, $warehouseId, $warehouseIds);
        $cogsFIFO = $cogsPack['fifo'] ?? 0.0;

        // Get expenses for the period
        $expenses = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            }, function ($q) use ($warehouseIds) {
                return $q->whereIn('warehouse_id', $warehouseIds);
            })
            ->when(!$viewRecords, function ($q) use ($user) {
                return $q->where('user_id', $user->id);
            })
            ->sum('amount');

        // Received sale returns reverse revenue; COGS above is already net of them.
        $saleReturns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('statut', 'received')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            }, function ($q) use ($warehouseIds) {
                return $q->whereIn('warehouse_id', $warehouseIds);
            })
            ->when(!$viewRecords, function ($q) use ($user) {
                return $q->where('user_id', $user->id);
            })
            ->sum('GrandTotal');

        $profit = $revenue - $saleReturns - $cogsFIFO - $expenses;

        return [
            'transactions' => $transactions,
            'revenue' => (float) $revenue,
            'tax' => (float) $tax,
            'discount' => (float) $discount,
            'profit' => (float) $profit,
        ];
    }

    /**
     * Execute sales by product report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @param array $filters
     * @return array
     */
    public function salesByProduct(string $dateFrom, string $dateTo, ?int $warehouseId = null, array $filters = []): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);
        $limit = $filters['limit'] ?? 10;
        $sortBy = $filters['sort_by'] ?? 'profit';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        // Build base query
        $query = SaleDetail::join('sales as s', 's.id', '=', 'sale_details.sale_id')
            ->join('products as p', 'p.id', '=', 'sale_details.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sale_details.product_variant_id')
            ->whereNull('s.deleted_at')
            ->where('s.statut', 'completed')
            ->whereBetween('sale_details.date', [$dateFrom, $dateTo]);

        if ($warehouseId) {
            $query->where('s.warehouse_id', $warehouseId);
        } else {
            $query->whereIn('s.warehouse_id', $warehouseIds);
        }

        if (!$viewRecords) {
            $query->where('s.user_id', $user->id);
        }

        // Aggregate by product (COALESCE name so chart/table never get null)
        $results = $query
            ->select(
                'sale_details.product_id',
                DB::raw('COALESCE(NULLIF(TRIM(p.name), ""), CONCAT("Product #", sale_details.product_id)) as name'),
                DB::raw('SUM(sale_details.quantity) as qty'),
                DB::raw('SUM(sale_details.total) as revenue'),
                DB::raw('SUM(sale_details.quantity * COALESCE(NULLIF(pv.cost, 0), p.cost, 0)) as cost')
            )
            ->groupBy('sale_details.product_id', 'p.name')
            ->get()
            ->map(function ($item) {
                $revenue = (float) $item->revenue;
                $cost = (float) $item->cost;
                $profit = $revenue - $cost;
                $marginPercent = $revenue > 0 ? (($profit / $revenue) * 100) : 0;
                $displayName = $item->name !== null && (string) $item->name !== '' ? (string) $item->name : 'Product #' . $item->product_id;

                return [
                    'product_id' => $item->product_id,
                    'name' => $displayName,
                    'qty' => (float) $item->qty,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin_percent' => round($marginPercent, 2),
                ];
            });

        // Sort
        $results = $results->sortBy(function ($item) use ($sortBy) {
            return $item[$sortBy];
        }, SORT_REGULAR, $sortDir === 'desc');

        // Limit
        return $results->take($limit)->values()->toArray();
    }

    /**
     * Execute late payments report
     *
     * @param array $filters
     * @return array
     */
    public function latePayments(array $filters = []): array
    {
        $minDaysOverdue = (int) ($filters['min_days_overdue'] ?? 30);
        $today = Carbon::today()->startOfDay();

        // Get sales with outstanding amounts (only invoice date on or before today)
        $sales = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereRaw('(GrandTotal - paid_amount) > 0.01')
            ->whereDate('date', '<=', $today->toDateString())
            ->with('client')
            ->get();

        $customerData = [];

        foreach ($sales as $sale) {
            $dueAmount = $sale->GrandTotal - $sale->paid_amount;
            $saleDate = Carbon::parse($sale->date)->startOfDay();
            // Use absolute difference: past invoices => positive days overdue (we already filter date <= today in the query)
            $daysOverdue = (int) $today->diffInDays($saleDate, true);

            if ($daysOverdue >= $minDaysOverdue) {
                $customerId = $sale->client_id;
                $customerName = $sale->client ? $sale->client->name : 'Unknown';

                if (!isset($customerData[$customerId])) {
                    $customerData[$customerId] = [
                        'customer_id' => $customerId,
                        'name' => $customerName,
                        'invoices_count' => 0,
                        'outstanding_amount' => 0,
                        'max_days_overdue' => 0,
                    ];
                }

                $customerData[$customerId]['invoices_count']++;
                $customerData[$customerId]['outstanding_amount'] += $dueAmount;
                $customerData[$customerId]['max_days_overdue'] = max(
                    $customerData[$customerId]['max_days_overdue'],
                    $daysOverdue
                );
            }
        }

        // Sort by outstanding_amount descending
        usort($customerData, function ($a, $b) {
            return $b['outstanding_amount'] <=> $a['outstanding_amount'];
        });

        return array_values($customerData);
    }

    /**
     * The period-over-period changes behind the insights, as data: one item per
     * metric that moved by more than 0.01. The SPA renders these through its
     * translation keys (Ai_Insight_*), so the text follows the user's language;
     * generateInsights() renders the same items as the legacy English sentence.
     *
     * @return array<int, array{metric: string, direction: string, from: float, to: float, delta: float, pct: float}>
     */
    public function insightItems(array $currentData, array $compareData): array
    {
        $items = [];
        foreach (['profit', 'revenue', 'discount'] as $metric) {
            $current = (float) ($currentData[$metric] ?? 0);
            $previous = (float) ($compareData[$metric] ?? 0);
            $delta = $current - $previous;
            if (abs($delta) <= 0.01) {
                continue;
            }
            $items[] = [
                'metric' => $metric,
                'direction' => $delta > 0 ? 'increased' : 'decreased',
                'from' => round($previous, 2),
                'to' => round($current, 2),
                'delta' => round($delta, 2),
                'pct' => $previous != 0 ? round(abs(($delta / abs($previous)) * 100), 1) : 0.0,
            ];
        }

        return $items;
    }

    /**
     * Generate insights by comparing two periods (English sentence; kept for
     * API consumers that read the plain `insights` string).
     *
     * @param array $currentData
     * @param array $compareData
     * @return string
     */
    public function generateInsights(array $currentData, array $compareData): string
    {
        $insights = [];
        foreach ($this->insightItems($currentData, $compareData) as $item) {
            if ($item['metric'] === 'profit') {
                $insights[] = sprintf(
                    'Profit %s from %s to %s (change: %s, %.1f%%)',
                    $item['direction'],
                    number_format($item['from'], 2),
                    number_format($item['to'], 2),
                    number_format($item['delta'], 2),
                    $item['pct']
                );
            } else {
                $insights[] = sprintf(
                    '%s changed by %s (from %s to %s)',
                    ucfirst($item['metric']),
                    number_format($item['delta'], 2),
                    number_format($item['from'], 2),
                    number_format($item['to'], 2)
                );
            }
        }

        if (empty($insights)) {
            return 'No significant changes detected between periods.';
        }

        return implode('. ', $insights) . '.';
    }

    /**
     * Get user's accessible warehouse IDs
     *
     * @param mixed $user
     * @param int|null $warehouseId
     * @return array
     */
    private function getUserWarehouseIds($user, ?int $warehouseId = null): array
    {
        if ($warehouseId) {
            return [$warehouseId];
        }

        if ($user && $user->is_all_warehouses) {
            return \App\Models\Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->toArray();
        }

        if ($user) {
            return \App\Models\UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        return [];
    }
}
