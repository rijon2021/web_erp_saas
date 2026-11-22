<?php

namespace App\Services\LicenseKeys;

use App\Models\LicenseKey;
use App\Models\LicensePolicy;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription invoices of Software License products.
 *
 *  - Generated keys: the first invoice issues the key(s); every later invoice
 *    RENEWS them — the keys of the latest earlier invoice are extended by the
 *    product's duration (from their current expiry, or from today if already
 *    expired) and the line is recorded in license_renewals, so it never issues
 *    a second key, even when the invoice is edited later.
 *  - Uploaded keys: every invoice delivers fresh keys (e.g. a yearly antivirus
 *    key); when none are in stock the invoice is backordered, never refused.
 *
 * $stockTaken: the caller already took the invoice's stock (the billing
 * command does; the "invoice today" path on create does not, so the uploaded
 * keys it hands out are taken out of stock here to keep qte == keys on hand).
 */
class LicenseSubscriptions
{
    public static function afterInvoice(Subscription $subscription, $invoice, bool $stockTaken): void
    {
        try {
            if (! $invoice instanceof Sale || ! LicenseFulfilment::ready()) {
                return;
            }
            $product = Product::find($subscription->product_id);
            $mode = LicenseFulfilment::modeOf($product);
            if ($mode === 'none') {
                return;
            }

            DB::transaction(function () use ($subscription, $invoice, $product, $mode, $stockTaken) {
                $fulfilment = app(LicenseFulfilment::class);

                if ($mode === 'generated' && self::renew($subscription, $invoice, $product)) {
                    return;
                }

                $newIds = $fulfilment->syncSale($invoice, null, null, null, true);
                if (! $stockTaken && $mode === 'pool' && $newIds && $invoice->warehouse_id) {
                    LicenseStock::post((int) $invoice->warehouse_id, LicenseStock::linesFor(LicenseKey::whereIn('id', $newIds)->get(), 'sub'),
                        'Subscription invoice '.$invoice->Ref, null);
                }
                app(LicenseDelivery::class)->afterCommit($newIds);
            });
        } catch (\Throwable $e) {
            Log::warning("License keys: subscription #{$subscription->id} invoice failed: ".$e->getMessage());
        }
    }

    /** Extend the keys of the latest earlier invoice; false when there are none (first invoice). */
    private static function renew(Subscription $subscription, Sale $invoice, Product $product): bool
    {
        if (! Schema::hasTable('license_renewals')) {
            return false;
        }
        $earlier = Sale::where('subscription_id', $subscription->id)->where('id', '!=', $invoice->id)->pluck('id');
        if ($earlier->isEmpty()) {
            return false;
        }
        $lastSaleId = LicenseKey::where('product_id', $product->id)
            ->where('source', LicenseKey::SOURCE_GENERATED)
            ->whereIn('sale_id', $earlier)
            ->whereNotNull('sale_detail_id')
            ->max('sale_id');
        if (! $lastSaleId) {
            return false;
        }

        $keys = LicenseKey::where('sale_id', $lastSaleId)->where('product_id', $product->id)
            ->whereNotNull('sale_detail_id')
            ->whereIn('status', [LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED])
            ->lockForUpdate()->get();
        if ($keys->isEmpty()) {
            return false;
        }

        $detailId = SaleDetail::where('sale_id', $invoice->id)->where('product_id', $product->id)->value('id');
        $policy = LicensePolicy::forLine((int) $product->id, null);
        $fulfilment = app(LicenseFulfilment::class);

        foreach ($keys as $key) {
            $from = $key->expires_at && $key->expires_at->isFuture() ? $key->expires_at->copy() : now();
            $to = $policy ? $policy->expiryFrom($from) : null; // lifetime: nothing to extend
            $old = $key->expires_at;
            if ($to) {
                $key->forceFill(['expires_at' => $to, 'reminded_at' => null])->save();
            }
            DB::table('license_renewals')->insert([
                'license_key_id' => $key->id,
                'sale_id' => $invoice->id,
                'sale_detail_id' => $detailId,
                'from_expires_at' => $old,
                'to_expires_at' => $to ?? $old,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $fulfilment->event($key->id, 'extended', 'Subscription renewal '.$invoice->Ref, null, [
                'from' => $old?->toDateString(), 'to' => $to?->toDateString() ?? $old?->toDateString(),
            ]);
        }

        return true;
    }
}
