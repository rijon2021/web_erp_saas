<?php

namespace App\Services\Couriers;

use App\Models\CourierSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How likely a customer is to send a courier parcel back, from their own
 * history: returned (or returning) ÷ finished (delivered + returned) courier
 * shipments. Levels: new (fewer finished parcels than the minimum), low,
 * medium (≥ half the high threshold) and high (≥ Courier Settings → high %).
 * The walk-in / default customer is never rated.
 */
final class ReturnRisk
{
    /**
     * @param  int[]  $clientIds
     * @return array<int, array{level:string, rate:float, returned:int, delivered:int, finished:int, total:int}>
     */
    public static function forClients(array $clientIds, ?array $warehouseIds = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        $walkIn = (int) DB::table('settings')->whereNull('deleted_at')->value('client_id');
        $ids = array_values(array_diff($ids, [$walkIn]));
        if (! $ids || ! Schema::hasColumn('shipments', 'courier_status')) {
            return [];
        }

        $settings = CourierSetting::current();
        $high = CourierSetting::hasPhase7Columns() ? $settings->riskHighPercent() : 30;
        $min = CourierSetting::hasPhase7Columns() ? $settings->riskMinShipments() : 3;

        $rows = DB::table('shipments')
            ->join('sales', 'sales.id', '=', 'shipments.sale_id')
            ->whereIn('sales.client_id', $ids)
            ->whereNull('sales.deleted_at')
            // Warehouse-restricted users see the history of their warehouses only.
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('sales.warehouse_id', $warehouseIds))
            ->whereNull('shipments.deleted_at')
            ->whereNotNull('shipments.courier_account_id')
            ->whereNotIn('shipments.courier_status', [CourierStatus::CANCELLED])
            ->groupBy('sales.client_id')
            ->selectRaw('sales.client_id as client_id, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN shipments.courier_status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN shipments.courier_status IN ('returned','returning') THEN 1 ELSE 0 END) as returned")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->client_id] = self::rate((int) $r->delivered, (int) $r->returned, (int) $r->total, $high, $min);
        }

        return $out;
    }

    public static function forClient(?int $clientId, ?array $warehouseIds = null): ?array
    {
        if (! $clientId) {
            return null;
        }
        $all = self::forClients([$clientId], $warehouseIds);

        return $all[$clientId] ?? (self::isWalkIn($clientId) ? null : self::rate(0, 0, 0, 30, 1));
    }

    public static function rate(int $delivered, int $returned, int $total, int $highPercent, int $minFinished): array
    {
        $finished = $delivered + $returned;
        $rate = $finished > 0 ? round($returned / $finished * 100, 1) : 0.0;
        $level = match (true) {
            $finished < $minFinished => 'new',
            $rate >= $highPercent => 'high',
            $rate >= $highPercent / 2 => 'medium',
            default => 'low',
        };

        return ['level' => $level, 'rate' => $rate, 'returned' => $returned, 'delivered' => $delivered, 'finished' => $finished, 'total' => $total];
    }

    private static function isWalkIn(int $clientId): bool
    {
        return (int) DB::table('settings')->whereNull('deleted_at')->value('client_id') === $clientId;
    }
}
