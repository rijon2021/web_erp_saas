<?php

namespace App\Services\LicenseKeys;

use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\product_warehouse;
use App\Services\AccountingV2\StockJournal;

/**
 * Stock side of uploaded license keys. The invariant is
 *   product_warehouse.qte == available keys (per product / edition / warehouse)
 * so every key that enters or leaves "available" outside a normal sale
 * (import, write-off, release on return / delete, put back, restock,
 * cross-warehouse claim) posts a stock adjustment here — one document per
 * call, one line per product / edition.
 */
class LicenseStock
{
    /**
     * @param  array<int, array{product_id:int, variant_id:?int, qty:int|float, type:'add'|'sub'}>  $lines
     */
    public static function post(int $warehouseId, array $lines, string $notes, ?int $userId): ?Adjustment
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) $l['qty'] > 0));
        if (! $lines || ! $warehouseId) {
            return null;
        }

        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'Ref' => app(\App\Http\Controllers\AdjustmentController::class)->getNumberOrder(),
            'warehouse_id' => $warehouseId,
            'items' => count($lines),
            'notes' => mb_substr($notes, 0, 190),
            'user_id' => $userId ?: (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
        ]);

        foreach ($lines as $l) {
            $variantId = $l['variant_id'] ?: null;
            AdjustmentDetail::create([
                'adjustment_id' => $adjustment->id,
                'product_id' => $l['product_id'],
                'product_variant_id' => $variantId,
                'quantity' => $l['qty'],
                'type' => $l['type'],
            ]);

            $pw = product_warehouse::where('deleted_at', '=', null)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $l['product_id'])
                ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'))
                ->lockForUpdate()
                ->first()
                ?: new product_warehouse([
                    'product_id' => $l['product_id'],
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variantId,
                    'manage_stock' => 1,
                    'qte' => 0,
                ]);
            $pw->qte = (float) $pw->qte + ($l['type'] === 'add' ? (float) $l['qty'] : -(float) $l['qty']);
            $pw->save();
        }

        StockJournal::adjustment($adjustment, created: true);

        return $adjustment;
    }

    /** One line per (product, edition) for a set of keys. */
    public static function linesFor($keys, string $type): array
    {
        $out = [];
        foreach ($keys as $k) {
            $gk = $k->product_id.'|'.(int) $k->product_variant_id;
            $out[$gk] ??= ['product_id' => (int) $k->product_id, 'variant_id' => $k->product_variant_id ? (int) $k->product_variant_id : null, 'qty' => 0, 'type' => $type];
            $out[$gk]['qty']++;
        }

        return array_values($out);
    }
}
