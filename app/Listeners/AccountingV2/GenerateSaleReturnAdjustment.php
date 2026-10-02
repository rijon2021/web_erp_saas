<?php

namespace App\Listeners\AccountingV2;

use App\Events\SaleReturnUpdated;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;

class GenerateSaleReturnAdjustment
{
    use SkipsWhenManual;

    public function handle(SaleReturnUpdated $event): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }

        AccountingHelper::fromSaleReturnAdjustment($event->saleReturn);
    }
}
