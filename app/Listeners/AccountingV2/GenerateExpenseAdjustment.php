<?php

namespace App\Listeners\AccountingV2;

use App\Events\ExpenseUpdated;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;

class GenerateExpenseAdjustment
{
    use SkipsWhenManual;

    public function handle(ExpenseUpdated $event): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }

        $expense = $event->expense;

        // A new paying account or date cannot be expressed as an amount delta:
        // reverse what was posted and post the expense again as it now stands.
        if ($expense->wasChanged(['account_id', 'date'])) {
            AccountingHelper::reverseExpense($expense);
            AccountingHelper::fromExpense($expense);

            return;
        }

        AccountingHelper::fromExpenseAdjustment($expense);
    }
}
