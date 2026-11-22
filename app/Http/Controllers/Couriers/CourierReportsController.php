<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\BaseController;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\CourierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Courier performance for a booking-date range: delivery / return rates,
 * COD collected / remitted / outstanding and courier charges — overall, per
 * courier account and per destination city. Rates count finished parcels
 * only (delivered vs returned); parcels still on the way are shown apart.
 */
class CourierReportsController extends BaseController
{
    private const RETURNED = [CourierStatus::RETURNED, CourierStatus::RETURNING];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_reports', CourierAccount::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer'],
        ]);
        $from = $data['from'] ?? now()->subDays(29)->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $base = Shipment::query()
            ->whereNull('shipments.deleted_at')
            ->whereNotNull('shipments.courier_account_id')
            ->whereDate('shipments.booked_at', '>=', $from)
            ->whereDate('shipments.booked_at', '<=', $to)
            ->when(! empty($data['account_id']), fn ($q) => $q->where('shipments.courier_account_id', (int) $data['account_id']))
            ->when(($scope = $this->warehouseScopeIds()) !== null, fn ($q) => $q->whereHas('sale', fn ($s) => $s->whereIn('warehouse_id', $scope)))
            // Users limited to their own records see their own sales' parcels only.
            ->when(! $request->user('api')->hasRecordView(), fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('user_id', $request->user('api')->id)));

        $summary = $this->metrics((clone $base))->first();
        $byAccount = $this->metrics((clone $base))->addSelect('shipments.courier_account_id')
            ->groupBy('shipments.courier_account_id')->get();
        $byCity = $this->metrics((clone $base))
            ->selectRaw("COALESCE(NULLIF(TRIM(shipments.destination_city), ''), '—') as city")
            ->groupBy('city')->orderByRaw('COUNT(*) DESC')->limit(25)->get();

        $accounts = CourierAccount::query()->withTrashed()->get(['id', 'name', 'courier', 'deleted_at'])->keyBy('id');

        return response()->json([
            'from' => $from,
            'to' => $to,
            'summary' => $this->present($summary),
            'by_account' => $byAccount->map(function ($row) use ($accounts) {
                $a = $accounts[$row->courier_account_id] ?? null;

                return ['account_id' => (int) $row->courier_account_id, 'account_name' => $a ? $a->name . ($a->deleted_at ? ' (deleted)' : '') : '#' . $row->courier_account_id,
                    'courier_name' => $a ? (CourierRegistry::meta($a->courier)['name'] ?? $a->courier) : null] + $this->present($row);
            })->sortByDesc('total')->values(),
            'by_city' => $byCity->map(fn ($row) => ['city' => $row->city] + $this->present($row))->values(),
            'accounts' => $accounts->values()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name . ($a->deleted_at ? ' (deleted)' : '')]),
        ]);
    }

    /** Aggregate columns shared by every grouping. */
    private function metrics($query)
    {
        $ret = "'" . implode("','", self::RETURNED) . "'";
        $active = "'" . implode("','", [CourierStatus::RETURNED, CourierStatus::CANCELLED]) . "'";

        return $query->reorder()->select([])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN shipments.courier_status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN shipments.courier_status IN ({$ret}) THEN 1 ELSE 0 END) as returned")
            ->selectRaw("SUM(CASE WHEN shipments.courier_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN shipments.courier_status = 'failed_attempt' THEN 1 ELSE 0 END) as failed_attempts")
            ->selectRaw("SUM(CASE WHEN shipments.cod_amount > 0 AND shipments.courier_status <> 'cancelled' THEN shipments.cod_amount ELSE 0 END) as cod_total")
            ->selectRaw("SUM(CASE WHEN shipments.cod_status = 'collected' THEN shipments.cod_amount ELSE 0 END) as cod_collected")
            ->selectRaw("SUM(CASE WHEN shipments.cod_status = 'remitted' THEN shipments.cod_amount ELSE 0 END) as cod_remitted")
            ->selectRaw("SUM(CASE WHEN shipments.cod_status IN ('pending','collected') AND shipments.courier_status NOT IN ({$active}) THEN shipments.cod_amount ELSE 0 END) as cod_outstanding")
            ->selectRaw('SUM(COALESCE(shipments.shipping_charges, 0)) as charges')
            ->selectRaw("AVG(CASE WHEN shipments.courier_status = 'delivered' AND shipments.delivered_at IS NOT NULL AND shipments.booked_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, shipments.booked_at, shipments.delivered_at) END) as avg_delivery_hours");
    }

    private function present($row): array
    {
        $total = (int) ($row->total ?? 0);
        $delivered = (int) ($row->delivered ?? 0);
        $returned = (int) ($row->returned ?? 0);
        $cancelled = (int) ($row->cancelled ?? 0);
        $finished = $delivered + $returned;
        $hours = $row->avg_delivery_hours ?? null;

        return [
            'total' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'cancelled' => $cancelled,
            'in_progress' => max(0, $total - $finished - $cancelled),
            'failed_attempts' => (int) ($row->failed_attempts ?? 0),
            'delivery_rate' => $finished ? round($delivered / $finished * 100, 1) : null,
            'return_rate' => $finished ? round($returned / $finished * 100, 1) : null,
            'cod_total' => round((float) ($row->cod_total ?? 0), 2),
            'cod_collected' => round((float) ($row->cod_collected ?? 0), 2),
            'cod_remitted' => round((float) ($row->cod_remitted ?? 0), 2),
            'cod_outstanding' => round((float) ($row->cod_outstanding ?? 0), 2),
            'charges' => round((float) ($row->charges ?? 0), 2),
            'avg_delivery_days' => $hours !== null ? round((float) $hours / 24, 1) : null,
        ];
    }
}
