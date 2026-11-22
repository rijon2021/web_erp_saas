<?php

namespace App\Services\Couriers;

use App\Http\Controllers\SalesReturnController;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Parcels the courier brought back. A returned shipment enters the review
 * queue (return_status = pending) and leaves it either:
 *  - restocked: a "received" sale return for the whole sale is created
 *    through SalesReturnController — the same code path as the Return button,
 *    so units, packs, combos, batches, serials and ZATCA credit notes all
 *    behave exactly as for a manual return;
 *  - dismissed: nothing goes back into stock (damaged, lost, kept by courier).
 */
class ReturnProcessor
{
    public function restock(Shipment $shipment, User $user, ?string $note = null): Shipment
    {
        return $this->exclusively($shipment, fn (Shipment $fresh) => $this->restockLocked($fresh, $user, $note));
    }

    public function dismiss(Shipment $shipment, User $user, ?string $note = null): Shipment
    {
        return $this->exclusively($shipment, fn (Shipment $fresh) => $this->dismissLocked($fresh, $user, $note));
    }

    /**
     * One decision per parcel: two clicks (or an automatic restock racing a
     * staff click) must not create two sale returns and add the stock twice.
     */
    private function exclusively(Shipment $shipment, callable $fn): Shipment
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('courier_return:' . (tenant('id') ?? 'central') . ':' . $shipment->id, 300);
        if (! $lock->get()) {
            throw new CourierException('This returned parcel is being handled right now. Refresh in a moment.');
        }
        try {
            return $fn($shipment->fresh() ?? $shipment);
        } finally {
            $lock->release();
        }
    }

    private function restockLocked(Shipment $shipment, User $user, ?string $note = null): Shipment
    {
        $this->assertPending($shipment);

        $sale = $shipment->sale;
        if (! $sale || $sale->deleted_at) {
            throw new CourierException('The sale of this shipment no longer exists.');
        }

        // One return per sale is what the sales screens expect; an existing
        // (manual) return means someone already handled the goods.
        if (SaleReturn::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->exists()) {
            throw new CourierException('This sale already has a return. Check it, then dismiss this parcel from the queue.');
        }

        $returnId = ActingUser::run($user, function () use ($sale, $user, $note, $shipment) {
            $controller = app(SalesReturnController::class);

            $builder = $this->asRequest($user, []);
            $built = $controller->create_sell_return($builder, $sale->id)->getData(true);

            $payload = $this->returnPayload($built, $note ?: 'Courier return · ' . $shipment->tracking_number);
            if (! $payload['details']) {
                throw new CourierException('Nothing on this sale can be returned to stock.');
            }

            $request = $this->asRequest($user, $payload);
            // SalesReturnController::store validates via the request() helper.
            $previous = app('request');
            app()->instance('request', $request);
            try {
                $controller->store($request);
            } finally {
                app()->instance('request', $previous);
            }

            return SaleReturn::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->latest('id')->value('id');
        });

        $shipment->forceFill([
            'return_status' => 'restocked',
            'return_note' => $note,
            'return_handled_at' => now(),
            'return_handled_by' => $user->id,
            'sale_return_id' => $returnId,
        ])->save();

        return $shipment;
    }

    private function dismissLocked(Shipment $shipment, User $user, ?string $note = null): Shipment
    {
        $this->assertPending($shipment);

        $shipment->forceFill([
            'return_status' => 'dismissed',
            'return_note' => $note,
            'return_handled_at' => now(),
            'return_handled_by' => $user->id,
        ])->save();

        return $shipment;
    }

    private function assertPending(Shipment $shipment): void
    {
        if ($shipment->return_status !== 'pending') {
            throw new CourierException('This parcel is not waiting for review.');
        }
    }

    private function asRequest(User $user, array $payload): Request
    {
        $request = Request::create('/api/returns_sale', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /** Turn create_sell_return's form data into a full-quantity store() payload. */
    private function returnPayload(array $built, string $note): array
    {
        $head = $built['sale_return'] ?? [];
        $lines = [];
        $total = 0.0;

        $packs = SaleDetail::query()
            ->whereIn('id', array_column($built['details'] ?? [], 'id'))
            ->get(['id', 'product_pack_id', 'pack_multiplier', 'pack_name'])
            ->keyBy('id');

        foreach ($built['details'] ?? [] as $d) {
            $qty = (float) ($d['sale_quantity'] ?? 0);
            // del = the product no longer has a stock row in this warehouse.
            if ($qty <= 0 || ! empty($d['del'])) {
                continue;
            }

            $price = (float) $d['Unit_price'];
            $discountNet = (float) $d['DiscountNet'];
            $taxPrice = (float) $d['tax_percent'] * (($price - $discountNet) / 100);
            $netPrice = (string) $d['tax_method'] === '1' ? $price - $discountNet : $price - $discountNet - $taxPrice;
            $subtotal = round(($netPrice * $qty) + ($taxPrice * $qty), 2);
            $total += $subtotal;

            $pack = $packs->get($d['id']);
            $lines[] = [
                'quantity' => $qty,
                'Unit_price' => $price,
                'sale_unit_id' => $d['sale_unit_id'] ?: null,
                'tax_percent' => $d['tax_percent'],
                'tax_method' => $d['tax_method'],
                'discount' => $d['discount'],
                'discount_Method' => $d['discount_Method'],
                'product_id' => $d['product_id'],
                'product_variant_id' => $d['product_variant_id'],
                'subtotal' => $subtotal,
                'imei_number' => $d['imei_number'],
                'is_batch_tracked' => $d['is_batch_tracked'] ?? false,
                'product_pack_id' => $pack?->product_pack_id,
                'pack_multiplier' => $pack && (float) $pack->pack_multiplier > 0 ? (float) $pack->pack_multiplier : 1,
                'pack_name' => $pack?->pack_name,
            ];
        }

        return [
            'date' => now()->toDateString(),
            'client_id' => $head['client_id'] ?? null,
            'warehouse_id' => $head['warehouse_id'] ?? null,
            'sale_id' => $head['sale_id'] ?? null,
            'tax_rate' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'shipping' => 0,
            'GrandTotal' => round($total, 2),
            'statut' => 'received',
            'notes' => $note,
            'details' => $lines,
        ];
    }
}
