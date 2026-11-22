<?php

namespace App\Listeners\AccountingV2;

use App\Events\CommissionReceiptCreated;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;

class GenerateCommissionReceiptJournal
{
    use SkipsWhenManual;

    public function handle(CommissionReceiptCreated $event): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }

        AccountingHelper::fromCommissionReceipt($event->receipt);
    }
}
