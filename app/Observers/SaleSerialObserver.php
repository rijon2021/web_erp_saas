<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\SerialNumberService;

/**
 * Serial / IMEI units only stay `sold` while their sale is a live, completed
 * sale. The sales screens release them themselves; this catches every other
 * path that cancels, downgrades or deletes a sale through the model — the
 * channel syncs (WooCommerce, Salla, Shopify, ...) and the online-store
 * screens. Releasing twice is a no-op.
 */
class SaleSerialObserver
{
    public function updated(Sale $sale): void
    {
        $leftCompleted = $sale->wasChanged('statut')
            && $sale->getOriginal('statut') === 'completed'
            && $sale->statut !== 'completed';
        $softDeleted = $sale->wasChanged('deleted_at') && $sale->deleted_at !== null;

        if ($leftCompleted || $softDeleted) {
            app(SerialNumberService::class)->releaseSale((int) $sale->id, $softDeleted ? 'Sale deleted' : 'Sale no longer completed');
        }
    }

    public function deleted(Sale $sale): void
    {
        app(SerialNumberService::class)->releaseSale((int) $sale->id, 'Sale deleted');
    }
}
