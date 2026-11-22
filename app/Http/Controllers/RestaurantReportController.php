<?php

namespace App\Http\Controllers;

use App\Models\RestaurantOrder;
use App\Models\RestaurantSetting;
use App\Models\Warehouse;
use App\Services\Restaurant\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant › Dashboard (rst_dashboard) and Reports (rst_reports).
 * GET restaurant/dashboard?period=today|week|month|custom&from=&to=&warehouse_id=
 * GET restaurant/reports/{sales|dishes|voids|staff|kitchen|reservations}?from=&to=&warehouse_id=
 */
class RestaurantReportController extends BaseController
{
    public const REPORTS = ['sales', 'dishes', 'voids', 'staff', 'kitchen', 'reservations'];

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_dashboard', RestaurantSetting::class);
        if (! $this->isReady()) {
            return response()->json(['ready' => false]);
        }
        $v = $request->validate([
            'period' => 'nullable|in:today,week,month,custom',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'warehouse_id' => 'nullable|integer',
        ]);
        $period = $v['period'] ?? 'today';
        $now = now();
        switch ($period) {
            case 'week':
                $from = $now->copy()->subDays(6)->startOfDay();
                $to = $now->copy();
                [$pf, $pt] = [$from->copy()->subDays(7), $to->copy()->subDays(7)];
                break;
            case 'month':
                $from = $now->copy()->startOfMonth();
                $to = $now->copy();
                [$pf, $pt] = [$from->copy()->subMonthNoOverflow(), $to->copy()->subMonthNoOverflow()];
                break;
            case 'custom':
                [$from, $to] = $this->range($v);
                $days = $from->diffInDays($to->copy()->startOfDay()) + 1;
                [$pf, $pt] = [$from->copy()->subDays($days), $to->copy()->subDays($days)];
                break;
            default:
                // Today so far vs the same weekday last week, up to the same time.
                $from = $now->copy()->startOfDay();
                $to = $now->copy();
                [$pf, $pt] = [$from->copy()->subDays(7), $to->copy()->subDays(7)];
        }
        $live = $period === 'today' || ($period !== 'custom' && $to->isToday());

        return response()->json([
            'ready' => true,
            'period' => $period,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'compare_from' => $pf->format('Y-m-d'),
            'compare_to' => $pt->format('Y-m-d'),
            'warehouses' => $this->branches(),
            'data' => $this->service($v)->dashboard($from, $to, $pf, $pt, $live),
            'server_time' => $now->format('Y-m-d H:i'),
        ]);
    }

    public function report(Request $request, string $type)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reports', RestaurantSetting::class);
        if (! in_array($type, self::REPORTS, true)) {
            abort(404);
        }
        if (! $this->isReady()) {
            return response()->json(['ready' => false]);
        }
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'warehouse_id' => 'nullable|integer',
        ]);
        [$from, $to] = $this->range($v);

        return response()->json([
            'ready' => true,
            'type' => $type,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'warehouses' => $this->branches(),
            'data' => $this->service($v)->{$type}($from, $to),
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array{0: Carbon, 1: Carbon} from (start of day) … to (end of day), at most a year; default the last 30 days. */
    private function range(array $v): array
    {
        $tz = config('app.timezone');
        $to = ! empty($v['to']) ? Carbon::createFromFormat('Y-m-d', $v['to'], $tz)->endOfDay() : now()->endOfDay();
        $from = ! empty($v['from']) ? Carbon::createFromFormat('Y-m-d', $v['from'], $tz)->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > ReportService::MAX_DAYS) {
            $from = $to->copy()->subDays(ReportService::MAX_DAYS - 1)->startOfDay();
        }

        return [$from, $to];
    }

    private function service(array $v): ReportService
    {
        $branch = ! empty($v['warehouse_id']) ? (int) $v['warehouse_id'] : null;
        $scope = $this->warehouseScopeIds();
        // A branch outside the user's warehouses shows nothing rather than everything.
        if ($branch && $scope !== null && ! in_array($branch, array_map('intval', $scope), true)) {
            $scope = [0];
        }

        return new ReportService($scope, $branch);
    }

    /** Branches the user may filter by (only worth a picker when more than one). */
    private function branches()
    {
        return $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']);
    }

    private function isReady(): bool
    {
        return RestaurantOrder::tablesReady() && Schema::hasTable('rst_order_payments');
    }
}
