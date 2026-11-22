<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfitReportController extends Controller
{
    /**
     * Profit analysis grouped by one dimension:
     *   GET report/profit/{dimension}  — product | category | unit | customer | date | warehouse
     *
     * Lines are completed sales net of received sale returns. Line revenue =
     * line total; line cost is the quantity converted to BASE units — pack
     * multiplier and sale-unit operator applied, exactly as stock is deducted —
     * times COALESCE(variant cost, product cost), because cost is held per base
     * unit. Returns {rows, totalRows, kpis, chart, warehouses}.
     * KPIs and chart cover the WHOLE filtered set; rows are paginated.
     */
    public function index(Request $request, $dimension)
    {
        // Reports_profit lives on ClientPolicy (same as the Profit & Loss report).
        $this->authorizeForUser($request->user('api'), 'Reports_profit', Client::class);

        $dimensions = ['product', 'category', 'unit', 'customer', 'date', 'warehouse'];
        abort_unless(in_array($dimension, $dimensions, true), 404);

        // ---- Inputs
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) ($request->page ?? 1));
        $offset = $perPage === -1 ? 0 : ($page - 1) * $perPage;
        $order = $request->SortField ?: 'profit';
        $dir = strtolower($request->SortType ?: 'desc') === 'asc' ? 'asc' : 'desc';

        $today = Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : $today->copy()->subDays(29)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : $today->toDateString();
        if ($from > $to) {
            $from = $to;
        }

        // ---- Warehouse scope
        $user = Auth::user();
        $allowed = null;
        if ($user && ! $user->is_all_warehouses) {
            $allowed = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
        }
        $warehouseId = $request->warehouse_id;
        if ($allowed !== null && $warehouseId && ! in_array((int) $warehouseId, $allowed, true)) {
            $warehouseId = null;
        }

        // Cost is stored per BASE unit, while a sale line is recorded in packs of
        // a sale unit: a case of 24 stores quantity = 1 with pack_multiplier = 24,
        // and a line sold in a unit such as "box of 6" carries an operator on that
        // unit. Both conversions have to be applied or the cost of anything not
        // sold in single base units comes out far too low and the profit far too
        // high. This mirrors the stock deduction in PosController.
        $costExpr = fn (string $line) => "{$line}.quantity
                     * COALESCE({$line}.pack_multiplier, 1)
                     * CASE WHEN cu.operator = '/'
                            THEN 1 / COALESCE(NULLIF(cu.operator_value, 0), 1)
                            ELSE COALESCE(cu.operator_value, 1)
                       END
                     * COALESCE(pv.cost, p.cost, 0)";

        // One row per line: completed sale lines, plus received return lines with
        // qty / revenue / cost negated so a return reverses its sale in every
        // grouping. Returns count on their own date, customer and warehouse — the
        // same period rule as the Profit & Loss report.
        $lineSource = function (string $detail, string $line, string $doc, string $hdr, string $fk, string $status, string $sign) use ($costExpr, $from, $to, $allowed, $warehouseId) {
            return DB::table("{$detail} as {$line}")
                ->join("{$doc} as {$hdr}", "{$hdr}.id", '=', "{$line}.{$fk}")
                ->join('products as p', 'p.id', '=', "{$line}.product_id")
                ->leftJoin('product_variants as pv', 'pv.id', '=', "{$line}.product_variant_id")
                // Unit the line was sold in, for the base-unit conversion in $costExpr.
                ->leftJoin('units as cu', function ($j) use ($line) {
                    $j->on(DB::raw('cu.id'), '=', DB::raw("COALESCE({$line}.sale_unit_id, p.unit_sale_id, p.unit_id)"));
                })
                ->whereNull("{$hdr}.deleted_at")
                ->where("{$hdr}.statut", $status)
                ->whereBetween("{$hdr}.date", [$from, $to])
                ->when($allowed !== null, fn ($q) => $q->whereIn("{$hdr}.warehouse_id", $allowed))
                ->when($warehouseId, fn ($q) => $q->where("{$hdr}.warehouse_id", $warehouseId))
                ->selectRaw("{$line}.product_id, {$line}.sale_unit_id, {$hdr}.client_id, {$hdr}.date, {$hdr}.warehouse_id,
                             {$sign}{$line}.quantity as qty,
                             {$sign}{$line}.total as revenue,
                             {$sign}({$costExpr($line)}) as cost");
        };

        $lines = $lineSource('sale_details', 'sd', 'sales', 's', 'sale_id', 'completed', '')
            ->unionAll($lineSource('sale_return_details', 'srd', 'sale_returns', 'sr', 'sale_return_id', 'received', '-'));

        $base = fn () => DB::query()->fromSub($lines, 'l')
            ->join('products as p', 'p.id', '=', 'l.product_id');

        // ---- Dimension: label expression, joins and group key
        $dim = [
            'product' => ['label' => 'p.name', 'group' => 'p.id', 'join' => null, 'search' => 'p.name'],
            'category' => ['label' => "COALESCE(c.name, '—')", 'group' => 'p.category_id', 'join' => fn ($q) => $q->leftJoin('categories as c', 'c.id', '=', 'p.category_id'), 'search' => 'c.name'],
            'unit' => ['label' => "COALESCE(u.ShortName, '—')", 'group' => 'u.id', 'join' => fn ($q) => $q->leftJoin('units as u', function ($j) {
                $j->on(DB::raw('u.id'), '=', DB::raw('COALESCE(l.sale_unit_id, p.unit_sale_id, p.unit_id)'));
            }), 'search' => 'u.ShortName'],
            'customer' => ['label' => 'cl.name', 'group' => 'l.client_id', 'join' => fn ($q) => $q->join('clients as cl', 'cl.id', '=', 'l.client_id'), 'search' => 'cl.name'],
            'date' => ['label' => 'l.date', 'group' => 'l.date', 'join' => null, 'search' => 'l.date'],
            'warehouse' => ['label' => 'w.name', 'group' => 'l.warehouse_id', 'join' => fn ($q) => $q->join('warehouses as w', 'w.id', '=', 'l.warehouse_id'), 'search' => 'w.name'],
        ][$dimension];

        $grouped = fn () => tap($base(), function ($q) use ($dim, $request) {
            if ($dim['join']) {
                ($dim['join'])($q);
            }
            if ($request->filled('search')) {
                $q->where(DB::raw($dim['search']), 'LIKE', '%'.$request->search.'%');
            }
            $q->groupBy(DB::raw($dim['group']))
                ->selectRaw("{$dim['label']} as label,
                             COALESCE(SUM(l.qty),0) as qty,
                             COALESCE(SUM(l.revenue),0) as revenue,
                             COALESCE(SUM(l.cost),0) as cost,
                             COALESCE(SUM(l.revenue) - SUM(l.cost),0) as profit");
        });

        // ---- Every group in ONE query. The count, the table page, the chart and
        // (without a search) the KPIs are all derived from it — each used to
        // re-run the same grouped aggregate over the whole date range. A group
        // set is at most one row per product / customer / day, so it fits easily.
        $groups = $grouped()->get()->each(function ($r) {
            $r->qty = (float) $r->qty;
            $r->revenue = (float) $r->revenue;
            $r->cost = (float) $r->cost;
            $r->profit = (float) $r->profit;
        });
        $totalRows = $groups->count();

        // Case-insensitive label order, as the MySQL collation sorted it.
        $sortBy = function ($rows, string $field, string $direction) {
            $sorted = $field === 'label'
                ? $rows->sortBy(fn ($r) => mb_strtolower((string) $r->label), SORT_STRING)
                : $rows->sortBy($field, SORT_NUMERIC);

            return ($direction === 'desc' ? $sorted->reverse() : $sorted)->values();
        };

        // ---- Table rows
        $sortable = ['label', 'qty', 'revenue', 'cost', 'profit'];
        $rows = $sortBy($groups, in_array($order, $sortable, true) ? $order : 'profit', $dir);
        if ($perPage !== -1) {
            $rows = $rows->slice($offset, $perPage)->values();
        }
        $rows = $rows->map(function ($r) {
            $r = clone $r;
            $r->qty = round($r->qty, 2);
            $r->revenue = round($r->revenue, 2);
            $r->cost = round($r->cost, 2);
            $r->profit = round($r->profit, 2);
            $r->margin = $r->revenue > 0 ? round($r->profit / $r->revenue * 100, 1) : 0;

            return $r;
        });

        // ---- KPIs over the whole filtered set. The search box narrows the
        // table and chart only, and the customer / warehouse groups inner-join
        // their table, so in those cases the KPIs keep their own query; otherwise
        // every sale line lands in exactly one group and the sums are equal.
        if ($request->filled('search') || in_array($dimension, ['customer', 'warehouse'], true)) {
            $k = $base()->selectRaw('COALESCE(SUM(l.revenue),0) as revenue, COALESCE(SUM(l.cost),0) as cost')->first();
            $kpiRevenue = (float) $k->revenue;
            $kpiCost = (float) $k->cost;
        } else {
            $kpiRevenue = $groups->sum('revenue');
            $kpiCost = $groups->sum('cost');
        }
        $kpis = [
            'revenue' => round($kpiRevenue, 2),
            'cost' => round($kpiCost, 2),
            'profit' => round($kpiRevenue - $kpiCost, 2),
            'margin' => $kpiRevenue > 0 ? round(($kpiRevenue - $kpiCost) / $kpiRevenue * 100, 1) : 0,
        ];

        // ---- Chart: chronological for dates, top 10 by profit otherwise
        $chart = ($dimension === 'date'
            ? $sortBy($groups, 'label', 'asc')->take(180)
            : $sortBy($groups, 'profit', 'desc')->take(10)
        )->values()->map(fn ($r) => [
            'label' => $r->label,
            'revenue' => round((float) $r->revenue, 2),
            'profit' => round((float) $r->profit, 2),
        ]);

        $warehouses = Warehouse::whereNull('deleted_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get(['id', 'name']);

        return response()->json([
            'rows' => $rows,
            'totalRows' => $totalRows,
            'kpis' => $kpis,
            'chart' => $chart,
            'warehouses' => $warehouses,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
