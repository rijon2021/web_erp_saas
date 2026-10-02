<?php

namespace App\Listeners\AccountingV2;

use App\Events\SaleReturnDeleted;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;

class GenerateSaleReturnReversal
{
    use SkipsWhenManual;

    public function handle(SaleReturnDeleted $event): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }

        AccountingHelper::reverseSaleReturn($event->saleReturn);
    }
}
