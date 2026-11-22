<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\CommissionService;

/**
 * Keeps a sale's commissions in step with the sale. Any change that moves the
 * commission base or the credited agent (payments edited or deleted, status
 * changes, the sale deleted...) recalculates a sale that already has
 * commissions, once the surrounding transaction commits.
 */
class CommissionSaleObserver
{
    const WATCHED = ['GrandTotal', 'paid_amount', 'statut', 'deleted_at', 'date', 'sales_agent_id', 'seller_id', 'user_id'];

    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged(self::WATCHED)) {
            app(CommissionService::class)->queueRecalculation((int) $sale->id);
        }
    }
}
