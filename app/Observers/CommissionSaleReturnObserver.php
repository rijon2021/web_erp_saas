<?php

namespace App\Observers;

use App\Models\SaleReturn;
use App\Services\CommissionService;

/**
 * Received returns lower a sale's commission base and refunds lower its paid
 * amount, so creating, changing, refunding or deleting a return recalculates
 * the commissions of the sale it belongs to.
 */
class CommissionSaleReturnObserver
{
    const WATCHED = ['GrandTotal', 'paid_amount', 'statut', 'deleted_at', 'sale_id'];

    public function created(SaleReturn $return): void
    {
        $this->queue($return->sale_id);
    }

    public function updated(SaleReturn $return): void
    {
        if (! $return->wasChanged(self::WATCHED)) {
            return;
        }
        $this->queue($return->sale_id);
        if ($return->wasChanged('sale_id')) {
            $this->queue($return->getOriginal('sale_id'));
        }
    }

    public function deleted(SaleReturn $return): void
    {
        $this->queue($return->sale_id);
    }

    protected function queue($saleId): void
    {
        if ($saleId) {
            app(CommissionService::class)->queueRecalculation((int) $saleId);
        }
    }
}
