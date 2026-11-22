<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The read-only business-data tools the model may call. Each tool declares the
 * permissions that unlock it; tools the user can't use are not even offered
 * to the model. Every query runs inside AiScope (warehouses, own records).
 *
 * Amounts are base-currency, as stored. Results are capped (MAX_ROWS) so a
 * tool result never floods the context.
 */
class AiDataTools
{
    private const MAX_ROWS = 50;

    public function __construct(private AiScope $scope)
    {
    }

    /**
     * Tool definitions for the API — always the FULL catalog, in a fixed
     * order. The tool list is part of the prefix Claude's thinking blocks are
     * bound to, so it must not change when a user's permissions do;
     * permissions are enforced in run() and summarized in the per-turn
     * context instead.
     */
    public function definitions(): array
    {
        return array_map(
            fn ($tool) => ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $tool['schema']],
            $this->catalog()
        );
    }

    /** Names of the tools this user may actually run. */
    public function available(): array
    {
        return array_values(array_map(
            fn ($tool) => $tool['name'],
            array_filter($this->catalog(), fn ($tool) => ! $tool['perms'] || $this->scope->can(...$tool['perms']))
        ));
    }

    /** Run one tool call; always returns a JSON string (errors included). */
    public function run(string $name, array $input): string
    {
        $tool = collect($this->catalog())->firstWhere('name', $name);
        if (! $tool || ($tool['perms'] && ! $this->scope->can(...$tool['perms']))) {
            return json_encode(['error' => "Tool '$name' is not available to this user."]);
        }

        try {
            $result = $this->{$tool['method']}($input);
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'The query failed: '.class_basename($e)];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /* ================================================================== */
    /* Catalog                                                             */
    /* ================================================================== */

    private function catalog(): array
    {
        $range = [
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD (inclusive).'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD (inclusive).'],
        ];
        $warehouse = ['warehouse_id' => ['type' => 'integer', 'description' => 'Limit to one warehouse (id from list_warehouses). Omit for all the user can see.']];
        $limit = ['limit' => ['type' => 'integer', 'description' => 'Max rows, 1-50 (default 10).']];

        return [
            [
                'name' => 'list_warehouses',
                'method' => 'listWarehouses',
                'perms' => [],
                'description' => 'List the warehouses/stores this user can see, with their ids. Call this before filtering by warehouse name.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'business_overview',
                'method' => 'businessOverview',
                'perms' => ['Sales_view', 'Reports_sales'],
                'description' => 'Key figures for a date range: sales (count, total, paid, due, by status), sale returns, purchases and expenses (when the user may see them), and an estimated gross profit. Start here for general "how is business" questions.',
                'schema' => ['type' => 'object', 'properties' => $range + $warehouse, 'required' => ['from', 'to']],
            ],
            [
                'name' => 'sales_breakdown',
                'method' => 'salesBreakdown',
                'perms' => ['Sales_view', 'Reports_sales'],
                'description' => 'Sales totals grouped by day, week, month, warehouse, customer, seller (salesperson), payment_status or category, for a date range. Returns count and revenue per group (category uses line totals).',
                'schema' => [
                    'type' => 'object',
                    'properties' => $range + $warehouse + $limit + [
                        'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'warehouse', 'customer', 'seller', 'payment_status', 'category']],
                    ],
                    'required' => ['from', 'to', 'group_by'],
                ],
            ],
            [
                'name' => 'top_products',
                'method' => 'topProducts',
                'perms' => ['Sales_view', 'Reports_sales', 'Top_products'],
                'description' => 'Best (or worst) selling products in a date range, ranked by quantity sold, revenue or estimated gross profit. Set order to "asc" for the slowest sellers among products that sold at least once.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $range + $warehouse + $limit + [
                        'rank_by' => ['type' => 'string', 'enum' => ['quantity', 'revenue', 'profit']],
                        'order' => ['type' => 'string', 'enum' => ['desc', 'asc']],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'customer_balances',
                'method' => 'customerBalances',
                'perms' => ['Customers_view', 'Reports_customers'],
                'description' => 'Customers with the largest unpaid balance on their sales (amount due = total - paid, over all time), with phone and number of unpaid sales.',
                'schema' => ['type' => 'object', 'properties' => $limit],
            ],
            [
                'name' => 'top_customers',
                'method' => 'topCustomers',
                'perms' => ['Customers_view', 'Reports_customers', 'Top_customers'],
                'description' => 'Customers ranked by purchase amount (or number of sales) in a date range.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $range + $warehouse + $limit + [
                        'rank_by' => ['type' => 'string', 'enum' => ['revenue', 'count']],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'stock_levels',
                'method' => 'stockLevels',
                'perms' => ['products_view', 'Reports_quantity_alerts', 'stock_report'],
                'description' => 'Current stock quantities per product (and variant) per warehouse. Filter to low stock (at or below the product alert quantity) or out of stock, or search by name/code.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $warehouse + $limit + [
                        'only' => ['type' => 'string', 'enum' => ['all', 'low', 'out']],
                        'search' => ['type' => 'string', 'description' => 'Product name or code contains.'],
                    ],
                ],
            ],
            [
                'name' => 'purchases_summary',
                'method' => 'purchasesSummary',
                'perms' => ['Purchases_view', 'Reports_purchase'],
                'description' => 'Purchase totals for a date range grouped by supplier, month or warehouse: count, total, paid, due.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $range + $warehouse + $limit + [
                        'group_by' => ['type' => 'string', 'enum' => ['supplier', 'month', 'warehouse']],
                    ],
                    'required' => ['from', 'to', 'group_by'],
                ],
            ],
            [
                'name' => 'expenses_summary',
                'method' => 'expensesSummary',
                'perms' => ['expense_view', 'expenses_report'],
                'description' => 'Expense totals for a date range grouped by category, month or warehouse.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $range + $warehouse + $limit + [
                        'group_by' => ['type' => 'string', 'enum' => ['category', 'month', 'warehouse']],
                    ],
                    'required' => ['from', 'to', 'group_by'],
                ],
            ],
            [
                'name' => 'find_records',
                'method' => 'findRecords',
                'perms' => [],
                'description' => 'Look up products, customers, suppliers, sales or purchases by name, code, phone or reference. Returns ids and basic fields.',
                'schema' => [
                    'type' => 'object',
                    'properties' => $limit + [
                        'type' => ['type' => 'string', 'enum' => ['product', 'customer', 'supplier', 'sale', 'purchase']],
                        'query' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'query'],
                ],
            ],
            [
                'name' => 'sale_details',
                'method' => 'saleDetails',
                'perms' => ['Sales_view'],
                'description' => 'Full details of one sale by its reference (e.g. SL_1001) or id: customer, warehouse, status, totals, payments and line items.',
                'schema' => [
                    'type' => 'object',
                    'properties' => ['reference' => ['type' => 'string', 'description' => 'Sale reference or numeric id.']],
                    'required' => ['reference'],
                ],
            ],
        ];
    }

    /* ================================================================== */
    /* Helpers                                                             */
    /* ================================================================== */

    private function range(array $in): array
    {
        try {
            $from = Carbon::parse($in['from'] ?? now()->startOfMonth())->toDateString();
            $to = Carbon::parse($in['to'] ?? now())->toDateString();
        } catch (\Throwable $e) {
            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
        }

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function limit(array $in, int $default = 10): int
    {
        return max(1, min(self::MAX_ROWS, (int) ($in['limit'] ?? $default)));
    }

    private function warehouseId(array $in): ?int
    {
        return isset($in['warehouse_id']) && $in['warehouse_id'] !== '' ? (int) $in['warehouse_id'] : null;
    }

    private function sales(array $in)
    {
        [$from, $to] = $this->range($in);

        return $this->scope->applyDocument(DB::table('sales'), 'sales', $this->warehouseId($in))
            ->whereBetween('sales.date', [$from, $to]);
    }

    private static function money($v): float
    {
        return round((float) $v, 2);
    }

    /** Estimated line cost: quantity × pack size × current (variant) cost. */
    private const LINE_COST = 'sale_details.quantity * COALESCE(sale_details.pack_multiplier, 1) * COALESCE(product_variants.cost, products.cost, 0)';

    /* ================================================================== */
    /* Tools                                                               */
    /* ================================================================== */

    private function listWarehouses(array $in): array
    {
        return ['warehouses' => DB::table('warehouses')->whereIn('id', $this->scope->warehouseIds)
            ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'city'])];
    }

    private function businessOverview(array $in): array
    {
        [$from, $to] = $this->range($in);
        $wid = $this->warehouseId($in);

        $sales = $this->sales($in)->selectRaw('COUNT(*) c, COALESCE(SUM(GrandTotal),0) total, COALESCE(SUM(paid_amount),0) paid')->first();
        $byStatus = $this->sales($in)->groupBy('statut')->selectRaw('statut, COUNT(*) c, COALESCE(SUM(GrandTotal),0) total')->get()
            ->mapWithKeys(fn ($r) => [$r->statut => ['count' => (int) $r->c, 'total' => self::money($r->total)]]);

        $lines = $this->sales($in)
            ->join('sale_details', 'sale_details.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_details.product_variant_id')
            ->selectRaw('COALESCE(SUM(sale_details.total),0) revenue, COALESCE(SUM('.self::LINE_COST.'),0) cost')
            ->first();

        $returns = $this->scope->applyDocument(DB::table('sale_returns'), 'sale_returns', $wid)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(GrandTotal),0) total')->first();

        $out = [
            'period' => compact('from', 'to'),
            'sales' => [
                'count' => (int) $sales->c,
                'total' => self::money($sales->total),
                'paid' => self::money($sales->paid),
                'due' => self::money($sales->total - $sales->paid),
                'by_status' => $byStatus,
            ],
            'sale_returns' => ['count' => (int) $returns->c, 'total' => self::money($returns->total)],
            'estimated_gross_profit' => [
                'line_revenue' => self::money($lines->revenue),
                'estimated_cost' => self::money($lines->cost),
                'estimated_profit' => self::money($lines->revenue - $lines->cost),
                'note' => 'Line totals minus current product cost; ignores order-level discounts, shipping and returns.',
            ],
        ];

        if ($this->scope->can('Purchases_view', 'Reports_purchase')) {
            $p = $this->scope->applyDocument(DB::table('purchases'), 'purchases', $wid)->whereBetween('date', [$from, $to])
                ->selectRaw('COUNT(*) c, COALESCE(SUM(GrandTotal),0) total, COALESCE(SUM(paid_amount),0) paid')->first();
            $out['purchases'] = ['count' => (int) $p->c, 'total' => self::money($p->total), 'due' => self::money($p->total - $p->paid)];
        }

        if ($this->scope->can('expense_view', 'expenses_report')) {
            $e = $this->scope->applyDocument(DB::table('expenses'), 'expenses', $wid)->whereBetween('date', [$from, $to])
                ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) total')->first();
            $out['expenses'] = ['count' => (int) $e->c, 'total' => self::money($e->total)];
        }

        return $out;
    }

    private function salesBreakdown(array $in): array
    {
        $groupBy = $in['group_by'] ?? 'day';
        $limit = $this->limit($in, in_array($groupBy, ['day', 'week', 'month'], true) ? 50 : 10);
        $q = $this->sales($in);

        switch ($groupBy) {
            case 'week':
                $q->selectRaw("DATE_FORMAT(MIN(sales.date), '%Y-%m-%d') as week_start, COUNT(*) c, SUM(GrandTotal) total")
                    ->groupByRaw('YEARWEEK(sales.date, 1)')->orderBy('week_start');
                break;
            case 'month':
                $q->selectRaw("DATE_FORMAT(sales.date, '%Y-%m') as month, COUNT(*) c, SUM(GrandTotal) total")
                    ->groupBy('month')->orderBy('month');
                break;
            case 'warehouse':
                $q->join('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')
                    ->selectRaw('warehouses.name as warehouse, COUNT(*) c, SUM(GrandTotal) total')
                    ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total');
                break;
            case 'customer':
                $q->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
                    ->selectRaw('clients.id as customer_id, clients.name as customer, COUNT(*) c, SUM(GrandTotal) total')
                    ->groupBy('clients.id', 'clients.name')->orderByDesc('total');
                break;
            case 'seller':
                $q->leftJoin('users', 'users.id', '=', DB::raw('COALESCE(sales.seller_id, sales.user_id)'))
                    ->selectRaw("CONCAT(COALESCE(users.firstname,''), ' ', COALESCE(users.lastname,'')) as seller, COUNT(*) c, SUM(GrandTotal) total")
                    ->groupBy('users.id', 'users.firstname', 'users.lastname')->orderByDesc('total');
                break;
            case 'payment_status':
                $q->selectRaw('payment_statut as payment_status, COUNT(*) c, SUM(GrandTotal) total, SUM(GrandTotal - paid_amount) due')
                    ->groupBy('payment_statut');
                break;
            case 'category':
                $q->join('sale_details', 'sale_details.sale_id', '=', 'sales.id')
                    ->leftJoin('products', 'products.id', '=', 'sale_details.product_id')
                    ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category, COUNT(DISTINCT sales.id) c, SUM(sale_details.total) total, SUM(sale_details.quantity) quantity")
                    ->groupBy('categories.id', 'categories.name')->orderByDesc('total');
                break;
            default:
                $q->selectRaw("DATE_FORMAT(sales.date, '%Y-%m-%d') as day, COUNT(*) c, SUM(GrandTotal) total")
                    ->groupBy('day')->orderBy('day');
        }

        $rows = $q->limit($limit)->get()->map(function ($r) {
            $row = (array) $r;
            $row['count'] = (int) $row['c'];
            unset($row['c']);
            foreach (['total', 'due', 'quantity'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = self::money($row[$k]);
                }
            }

            return $row;
        });

        return ['group_by' => $groupBy, 'period' => array_combine(['from', 'to'], $this->range($in)), 'rows' => $rows];
    }

    private function topProducts(array $in): array
    {
        $rankBy = $in['rank_by'] ?? 'quantity';
        $order = ($in['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = ['quantity' => 'quantity', 'revenue' => 'revenue', 'profit' => 'est_profit'][$rankBy] ?? 'quantity';

        $rows = $this->sales($in)
            ->join('sale_details', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_details.product_variant_id')
            ->selectRaw('products.id, products.name, products.code, product_variants.name as variant,
                SUM(sale_details.quantity * COALESCE(sale_details.pack_multiplier, 1)) quantity,
                SUM(sale_details.total) revenue,
                SUM(sale_details.total) - SUM('.self::LINE_COST.') est_profit')
            ->groupBy('products.id', 'products.name', 'products.code', 'product_variants.id', 'product_variants.name')
            ->orderBy($sort, $order)
            ->limit($this->limit($in))
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'name' => $r->name.($r->variant ? ' — '.$r->variant : ''),
                'code' => $r->code,
                'quantity' => round((float) $r->quantity, 3),
                'revenue' => self::money($r->revenue),
                'estimated_profit' => self::money($r->est_profit),
            ]);

        return ['rank_by' => $rankBy, 'order' => $order, 'period' => array_combine(['from', 'to'], $this->range($in)), 'products' => $rows];
    }

    private function customerBalances(array $in): array
    {
        $rows = $this->scope->applyDocument(DB::table('sales'), 'sales', null)
            ->join('clients', 'clients.id', '=', 'sales.client_id')
            ->whereRaw('sales.GrandTotal - sales.paid_amount > 0.009')
            ->selectRaw('clients.id, clients.name, clients.phone, COUNT(*) unpaid_sales, SUM(sales.GrandTotal - sales.paid_amount) due')
            ->groupBy('clients.id', 'clients.name', 'clients.phone')
            ->orderByDesc('due')
            ->limit($this->limit($in))
            ->get()
            ->map(fn ($r) => ['customer_id' => (int) $r->id, 'name' => $r->name, 'phone' => $r->phone, 'unpaid_sales' => (int) $r->unpaid_sales, 'due' => self::money($r->due)]);

        return ['customers' => $rows, 'note' => 'Due from sales only; opening balances and service jobs not included.'];
    }

    private function topCustomers(array $in): array
    {
        $sort = ($in['rank_by'] ?? 'revenue') === 'count' ? 'c' : 'total';

        $rows = $this->sales($in)
            ->join('clients', 'clients.id', '=', 'sales.client_id')
            ->selectRaw('clients.id, clients.name, clients.phone, COUNT(*) c, SUM(GrandTotal) total')
            ->groupBy('clients.id', 'clients.name', 'clients.phone')
            ->orderByDesc($sort)
            ->limit($this->limit($in))
            ->get()
            ->map(fn ($r) => ['customer_id' => (int) $r->id, 'name' => $r->name, 'phone' => $r->phone, 'sales' => (int) $r->c, 'total' => self::money($r->total)]);

        return ['period' => array_combine(['from', 'to'], $this->range($in)), 'customers' => $rows];
    }

    private function stockLevels(array $in): array
    {
        $only = $in['only'] ?? 'all';
        $q = DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_warehouse.product_variant_id')
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('products.type', '!=', 'is_service')
            ->whereIn('product_warehouse.warehouse_id', $this->scope->warehouses($this->warehouseId($in)));

        if (! empty($in['search'])) {
            $s = '%'.trim((string) $in['search']).'%';
            $q->where(fn ($qq) => $qq->where('products.name', 'like', $s)->orWhere('products.code', 'like', $s)->orWhere('product_variants.name', 'like', $s));
        }
        if ($only === 'low') {
            $q->whereRaw('product_warehouse.qte <= COALESCE(products.stock_alert, 0)');
        } elseif ($only === 'out') {
            $q->where('product_warehouse.qte', '<=', 0);
        }

        $total = (clone $q)->count();
        $rows = $q->orderBy('product_warehouse.qte')
            ->limit($this->limit($in, 20))
            ->get(['products.id', 'products.name', 'products.code', 'product_variants.name as variant', 'warehouses.name as warehouse', 'product_warehouse.qte', 'products.stock_alert'])
            ->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'name' => $r->name.($r->variant ? ' — '.$r->variant : ''),
                'code' => $r->code,
                'warehouse' => $r->warehouse,
                'quantity' => round((float) $r->qte, 3),
                'alert_quantity' => round((float) $r->stock_alert, 3),
            ]);

        return ['filter' => $only, 'matching_rows' => $total, 'rows' => $rows];
    }

    private function purchasesSummary(array $in): array
    {
        [$from, $to] = $this->range($in);
        $groupBy = $in['group_by'] ?? 'supplier';
        $q = $this->scope->applyDocument(DB::table('purchases'), 'purchases', $this->warehouseId($in))
            ->whereBetween('purchases.date', [$from, $to]);
        $agg = 'COUNT(*) c, SUM(purchases.GrandTotal) total, SUM(purchases.GrandTotal - purchases.paid_amount) due';

        if ($groupBy === 'month') {
            $q->selectRaw("DATE_FORMAT(purchases.date, '%Y-%m') as month, $agg")->groupBy('month')->orderBy('month');
        } elseif ($groupBy === 'warehouse') {
            $q->join('warehouses', 'warehouses.id', '=', 'purchases.warehouse_id')
                ->selectRaw("warehouses.name as warehouse, $agg")->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total');
        } else {
            $q->leftJoin('providers', 'providers.id', '=', 'purchases.provider_id')
                ->selectRaw("providers.name as supplier, $agg")->groupBy('providers.id', 'providers.name')->orderByDesc('total');
        }

        $rows = $q->limit($this->limit($in, $groupBy === 'month' ? 24 : 10))->get()->map(function ($r) {
            $row = (array) $r;
            $row['count'] = (int) $row['c'];
            unset($row['c']);
            $row['total'] = self::money($row['total']);
            $row['due'] = self::money($row['due']);

            return $row;
        });

        return ['group_by' => $groupBy, 'period' => compact('from', 'to'), 'rows' => $rows];
    }

    private function expensesSummary(array $in): array
    {
        [$from, $to] = $this->range($in);
        $groupBy = $in['group_by'] ?? 'category';
        $q = $this->scope->applyDocument(DB::table('expenses'), 'expenses', $this->warehouseId($in))
            ->whereBetween('expenses.date', [$from, $to]);

        if ($groupBy === 'month') {
            $q->selectRaw("DATE_FORMAT(expenses.date, '%Y-%m') as month, COUNT(*) c, SUM(amount) total")->groupBy('month')->orderBy('month');
        } elseif ($groupBy === 'warehouse') {
            $q->join('warehouses', 'warehouses.id', '=', 'expenses.warehouse_id')
                ->selectRaw('warehouses.name as warehouse, COUNT(*) c, SUM(amount) total')->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total');
        } else {
            $q->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->selectRaw("COALESCE(expense_categories.name, 'Uncategorized') as category, COUNT(*) c, SUM(amount) total")
                ->groupBy('expense_categories.id', 'expense_categories.name')->orderByDesc('total');
        }

        $rows = $q->limit($this->limit($in, $groupBy === 'month' ? 24 : 10))->get()->map(function ($r) {
            $row = (array) $r;
            $row['count'] = (int) $row['c'];
            unset($row['c']);
            $row['total'] = self::money($row['total']);

            return $row;
        });

        return ['group_by' => $groupBy, 'period' => compact('from', 'to'), 'rows' => $rows];
    }

    private function findRecords(array $in): array
    {
        $type = $in['type'] ?? 'product';
        $s = '%'.trim((string) ($in['query'] ?? '')).'%';
        $limit = $this->limit($in);

        $needs = [
            'product' => ['products_view'], 'customer' => ['Customers_view'], 'supplier' => ['Suppliers_view'],
            'sale' => ['Sales_view'], 'purchase' => ['Purchases_view'],
        ][$type] ?? null;
        if (! $needs || ! $this->scope->can(...$needs)) {
            return ['error' => "This user may not look up {$type} records."];
        }

        switch ($type) {
            case 'customer':
                $rows = DB::table('clients')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s))
                    ->limit($limit)->get(['id', 'name', 'code', 'phone', 'email', 'city']);
                break;
            case 'supplier':
                $rows = DB::table('providers')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s))
                    ->limit($limit)->get(['id', 'name', 'code', 'phone', 'email', 'city']);
                break;
            case 'sale':
            case 'purchase':
                $table = $type === 'sale' ? 'sales' : 'purchases';
                $party = $type === 'sale' ? ['clients', 'client_id'] : ['providers', 'provider_id'];
                $rows = $this->scope->applyDocument(DB::table($table), $table, null)
                    ->leftJoin($party[0], $party[0].'.id', '=', "$table.{$party[1]}")
                    ->where(fn ($q) => $q->where("$table.Ref", 'like', $s)->orWhere($party[0].'.name', 'like', $s))
                    ->orderByDesc("$table.id")
                    ->limit($limit)
                    ->get(["$table.id", "$table.Ref as reference", "$table.date", $party[0].'.name as party', "$table.GrandTotal as total", "$table.paid_amount as paid", "$table.statut as status", "$table.payment_statut as payment_status"]);
                break;
            default:
                $rows = DB::table('products')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('code', 'like', $s))
                    ->limit($limit)->get(['id', 'name', 'code', 'price', 'cost', 'stock_alert']);
        }

        return ['type' => $type, 'results' => $rows];
    }

    private function saleDetails(array $in): array
    {
        $ref = trim((string) ($in['reference'] ?? ''));
        $sale = $this->scope->applyDocument(DB::table('sales'), 'sales', null)
            ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')
            ->where(fn ($q) => ctype_digit($ref) ? $q->where('sales.id', (int) $ref)->orWhere('sales.Ref', $ref) : $q->where('sales.Ref', $ref))
            ->first(['sales.id', 'sales.Ref', 'sales.date', 'sales.time', 'clients.name as customer', 'warehouses.name as warehouse',
                'sales.statut', 'sales.payment_statut', 'sales.GrandTotal', 'sales.paid_amount', 'sales.discount', 'sales.shipping', 'sales.TaxNet', 'sales.notes']);

        if (! $sale) {
            return ['error' => "No sale '$ref' visible to this user."];
        }

        $items = DB::table('sale_details')
            ->leftJoin('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_details.product_variant_id')
            ->where('sale_details.sale_id', $sale->id)
            ->get(['products.name', 'products.code', 'product_variants.name as variant', 'sale_details.quantity', 'sale_details.price', 'sale_details.total'])
            ->map(fn ($r) => [
                'product' => $r->name.($r->variant ? ' — '.$r->variant : ''),
                'code' => $r->code,
                'quantity' => round((float) $r->quantity, 3),
                'unit_price' => self::money($r->price),
                'line_total' => self::money($r->total),
            ]);

        $payments = DB::table('payment_sales')->whereNull('deleted_at')->where('sale_id', $sale->id)
            ->get(['date', 'Ref', 'montant'])
            ->map(fn ($p) => ['date' => $p->date, 'reference' => $p->Ref, 'amount' => self::money($p->montant)]);

        return [
            'sale' => [
                'id' => (int) $sale->id,
                'reference' => $sale->Ref,
                'date' => $sale->date.($sale->time ? ' '.$sale->time : ''),
                'customer' => $sale->customer,
                'warehouse' => $sale->warehouse,
                'status' => $sale->statut,
                'payment_status' => $sale->payment_statut,
                'total' => self::money($sale->GrandTotal),
                'paid' => self::money($sale->paid_amount),
                'due' => self::money($sale->GrandTotal - $sale->paid_amount),
                'discount' => self::money($sale->discount),
                'shipping' => self::money($sale->shipping),
                'tax' => self::money($sale->TaxNet),
                'notes' => $sale->notes,
            ],
            'items' => $items,
            'payments' => $payments,
        ];
    }
}
