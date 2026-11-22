<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shipment;
use App\Models\Unit;
use App\Models\product_warehouse;
use App\Services\EInvoicing\FiscalLock;
use Illuminate\Support\Facades\DB;

/**
 * What every shop import has to do to a sale the way the sale screens do it.
 *
 * The rule behind all of it: stock has moved exactly while a sale is
 * completed. SalesController restores stock on delete and on edit only for a
 * completed sale, so an import that takes stock for any other status leaves
 * those units gone for good. Callers change `statut` and move stock together,
 * which is what followStatus() is for.
 */
class ChannelSales
{
    /**
     * Take stock for a sale ($direction -1) or put it back (1): in the line's
     * unit and pack size, components instead of a virtual combo, nothing for
     * services, batches first-expired-first-out. Serials are released by
     * SaleSerialObserver when the sale changes.
     */
    public static function moveStock(Sale $sale, int $direction): void
    {
        $details = $sale->details()->get();

        foreach ($details as $detail) {
            $product = Product::find($detail->product_id);
            if (! $product || $product->type === 'is_service') {
                continue;
            }

            $unit = Unit::find($detail->sale_unit_id ?: $product->unit_sale_id);
            if (! $unit) {
                continue;
            }

            $pack = (float) ($detail->pack_multiplier ?? 0) > 0 ? (float) $detail->pack_multiplier : 1;
            $quantity = $direction * ComboStockService::baseQty((float) $detail->quantity * $pack, $unit);

            if (! $detail->product_variant_id
                && app(ComboStockService::class)->moveComponents((int) $detail->product_id, (int) $sale->warehouse_id, $quantity)) {
                continue;
            }

            $row = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $sale->warehouse_id)
                ->where('product_id', $detail->product_id)
                ->when(
                    $detail->product_variant_id,
                    fn ($q) => $q->where('product_variant_id', $detail->product_variant_id),
                    fn ($q) => $q->whereNull('product_variant_id')
                )
                ->lockForUpdate()->first();

            // Never clamped at zero: with Allow Overselling stock is legitimately
            // negative, and restoring 5 on -10 has to give -5.
            if ($row) {
                $row->qte = (float) $row->qte + $quantity;
                $row->save();
            }
        }

        $batches = app(BatchService::class);
        if ($batches->isSupported()) {
            $direction < 0
                ? $batches->applyForSaleWithAutoFallback($sale, $details->map(fn ($d) => ['product_id' => $d->product_id])->all(), $details)
                : $batches->reverseForSaleDetails($details);
        }

        // Software License products: a shop order is never refused for lack of
        // keys — what is missing is backordered and filled when keys arrive.
        if ($direction < 0) {
            \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWriteLenient($sale);
        }
    }

    /**
     * Give the sale a new status, moving stock when it enters or leaves
     * completed. Returns whether anything changed.
     */
    public static function followStatus(Sale $sale, string $statut): bool
    {
        // A sale accepted by a tax authority is frozen, as on the screens.
        if ($sale->statut === $statut || FiscalLock::isLocked($sale)) {
            return false;
        }

        DB::transaction(function () use ($sale, $statut) {
            $wasCompleted = $sale->statut === 'completed';
            if ($wasCompleted !== ($statut === 'completed')) {
                self::moveStock($sale, $wasCompleted ? 1 : -1);
            }

            $sale->statut = $statut;
            $sale->save();

            // Keys follow the status: completed → claim / issue, otherwise → taken back.
            \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWriteLenient($sale);
        }, 3);

        return true;
    }

    /**
     * The shop cancelled the order: undo the sale the way SalesController::destroy
     * does. Stock back if it had been taken, shipment gone, lines and
     * payments gone, the sale soft-deleted with $extra attributes set on it.
     *
     * Returns false, and does nothing, when the goods were delivered, the sale
     * has a return, or a tax authority has accepted it: it has evidently been
     * handled by a person, and the screens refuse those deletes too.
     */
    public static function reverse(Sale $sale, array $extra = []): bool
    {
        if ($sale->shipping_status === 'delivered'
            || FiscalLock::isLocked($sale, true)
            || SaleReturn::where('sale_id', $sale->id)->whereNull('deleted_at')->exists()) {
            return false;
        }

        DB::transaction(function () use ($sale, $extra) {
            if ($sale->statut === 'completed') {
                self::moveStock($sale, 1);
            }

            if ($shipment = Shipment::where('sale_id', $sale->id)->first()) {
                $shipment->delete();
            }

            $sale->details()->delete();

            foreach (PaymentSale::where('sale_id', $sale->id)->whereNull('deleted_at')->get() as $payment) {
                Account::adjustBalance($payment->account_id, -(float) $payment->montant);
                $payment->delete();
            }

            // Sale has no SoftDeletes trait: delete() would remove the row,
            // and the foreign key from the kept payment rows refuses that.
            foreach ($extra as $key => $value) {
                $sale->{$key} = $value;
            }
            $sale->deleted_at = now();
            $sale->shipping_status = null;
            $sale->save();

            // Software License keys come back (uploaded → Returned for review, generated → revoked).
            app(\App\Services\LicenseKeys\LicenseFulfilment::class)->releaseSale((int) $sale->id);
            \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWriteLenient($sale); // clears its backorders
        }, 3);

        return true;
    }

    /**
     * Record money received from the shop and bring the sale's paid figures up
     * to date. $title is the shop's payment method name; $ref must be unique
     * per shop order so a repeated import can see the row already exists.
     */
    public static function recordPayment(Sale $sale, float $amount, string $ref, string $title, string $notes, ?string $date = null): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $method = self::paymentMethodFor($title);

        PaymentSale::create([
            'sale_id' => $sale->id,
            'date' => $date ?: now()->toDateString(),
            'montant' => $amount,
            'Ref' => $ref,
            'change' => 0,
            'payment_method_id' => $method ? (int) $method->id : null,
            'user_id' => $sale->user_id,
            'notes' => $notes,
            'account_id' => null,
        ]);

        $sale->paid_amount = round((float) $sale->paid_amount + $amount, 2);
        $sale->payment_statut = Sale::paymentStatusFor($sale->GrandTotal, $sale->paid_amount);
        $sale->save();
    }

    /**
     * The shop says the goods reached the customer. Nothing else writes
     * sales.shipping_status for an imported order, so without this reverse()
     * never refuses: a delivered, paid marketplace order that is later
     * cancelled or returned would be wiped out instead of left for a person.
     */
    public static function markDelivered(Sale $sale): void
    {
        if ($sale->shipping_status !== 'delivered') {
            $sale->shipping_status = 'delivered';
            $sale->save();
        }
    }

    /** What is still owed on a sale, at cents. */
    public static function due(Sale $sale): float
    {
        return max(0.0, round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2));
    }

    /** By name; "Cash On Delivery" has no twin here, but it is cash. */
    public static function paymentMethodFor(string $title): ?PaymentMethod
    {
        $method = $title !== '' ? PaymentMethod::where('name', $title)->first() : null;

        if (! $method && stripos($title, 'cash') !== false) {
            $method = PaymentMethod::where('name', 'like', '%cash%')->first();
        }

        return $method ?: PaymentMethod::orderBy('id')->first();
    }
}
