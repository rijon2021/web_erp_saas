<?php

namespace App\Listeners\AccountingV2;

use App\Events\PurchaseReturnCreated;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;

class GeneratePurchaseReturnJournal
{
    use SkipsWhenManual;

    public function handle(PurchaseReturnCreated $event): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }

        AccountingHelper::fromPurchaseReturn($event->purchaseReturn);
    }
}
