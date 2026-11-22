<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\Warehouse;
use App\Services\Booking\BookingReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Booking Management › Dashboard (bookings or bookings_reports; money only
 * with bookings_reports) and Reports (bookings_reports).
 * GET bookings/dashboard?period=today|week|next7|month|custom&from=&to=&warehouse_id=
 * GET bookings/reports/{bookings|services|staff|times|website|lost|customers}?from=&to=&warehouse_id=
 */
class BookingReportController extends BaseController
{
    public function dashboard(Request $request)
    {
        $user = $request->user('api');
        $gate = Gate::forUser($user);
        $money = $gate->allows('bookings_reports', Booking::class);
        if (! $money && ! $gate->allows('view', Booking::class)) {
            abort(403);
        }
        if (! $this->isReady()) {
            return response()->json(['ready' => false]);
        }
        $v = $request->validate([
            'period' => 'nullable|in:today,week,next7,month,custom',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'warehouse_id' => 'nullable|integer',
        ]);
        $period = $v['period'] ?? 'today';
        $today = now()->startOfDay();
        switch ($period) {
            case 'week':
                [$from, $to] = [$today->copy()->subDays(6), $today->copy()->endOfDay()];
                break;
            case 'next7':
                [$from, $to] = [$today->copy(), $today->copy()->addDays(6)->endOfDay()];
                break;
            case 'month':
                // The whole month, upcoming bookings included.
                [$from, $to] = [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()];
                break;
            case 'custom':
                [$from, $to] = $this->range($v);
                break;
            default:
                [$from, $to] = [$today->copy(), $today->copy()->endOfDay()];
        }
        if ($period === 'month') {
            [$pf, $pt] = [$from->copy()->subMonthNoOverflow()->startOfMonth(), $from->copy()->subMonthNoOverflow()->endOfMonth()];
        } else {
            // Today → the same weekday last week; otherwise the span just before.
            $days = $period === 'today' ? 7 : $from->diffInDays($to->copy()->startOfDay()) + 1;
            [$pf, $pt] = [$from->copy()->subDays($days), $to->copy()->subDays($days)];
        }
        $withToday = now()->between($from, $to);
        $data = $this->service($v)->dashboard($from, $to, $pf, $pt, $withToday);
        if (! $money) {
            $data['kpis']['revenue'] = null;
            $data['booked_value'] = null;
            foreach (['services', 'staff'] as $list) {
                $data[$list] = array_map(function ($r) {
                    $r['revenue'] = null;
                    unset($r['avg_revenue']);

                    return $r;
                }, $data[$list]);
            }
        }

        return response()->json([
            'ready' => true,
            'period' => $period,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'compare_from' => $pf->format('Y-m-d'),
            'compare_to' => $pt->format('Y-m-d'),
            'money' => $money,
            'warehouses' => $this->branches(),
            'data' => $data,
            'server_time' => now()->format('Y-m-d H:i'),
        ]);
    }

    public function report(Request $request, string $type)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_reports', Booking::class);
        if (! in_array($type, BookingReportService::REPORTS, true)) {
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
        if ($from->diffInDays($to) > BookingReportService::MAX_DAYS) {
            $from = $to->copy()->subDays(BookingReportService::MAX_DAYS - 1)->startOfDay();
        }

        return [$from, $to];
    }

    private function service(array $v): BookingReportService
    {
        $branch = ! empty($v['warehouse_id']) ? (int) $v['warehouse_id'] : null;
        $scope = $this->warehouseScopeIds();
        // A branch outside the user's warehouses shows nothing rather than everything.
        if ($branch && $scope !== null && ! in_array($branch, array_map('intval', $scope), true)) {
            $scope = [0];
        }

        return new BookingReportService($scope, $branch);
    }

    /** Branches the user may filter by (only worth a picker when more than one). */
    private function branches()
    {
        return $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']);
    }

    private function isReady(): bool
    {
        return Booking::workflowReady() && BookingServiceDetail::tablesReady();
    }
}
