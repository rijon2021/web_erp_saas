<?php

namespace App\Services\Rental;

use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Models\Account;
use App\Models\RentalDepositTransaction;
use App\Models\RentalExpense;
use App\Models\RentalInvoice;
use App\Models\RentalPayment;
use App\Services\AccountingV2\AccountingHelper;

/**
 * Rental bookkeeping, on top of the AccountingV2 helper:
 *
 *  invoice issued     Dr Accounts Receivable  / Cr Rental Income (+ Cr Tax Payable)
 *  late fee charged   Dr Accounts Receivable  / Cr Late Fee Income   (posted as the delta when a
 *                                                                   payment, waiver or void settles it)
 *  payment received   Dr Cash/Bank            / Cr Accounts Receivable
 *  paid from deposit  Dr Customer Deposits    / Cr Accounts Receivable
 *  deposit collected  Dr Cash/Bank            / Cr Customer Deposits (liability)
 *  deposit refunded   Dr Customer Deposits    / Cr Cash/Bank
 *  deposit retained   Dr Customer Deposits    / Cr Retained Deposit Income
 *  expense paid       Dr Rental Expenses      / Cr Cash/Bank
 *  and the exact reversal of each.
 *
 * Journals follow the app-wide "automatic journals" switch. Legacy cash/bank
 * account balances (accounts.balance) are always kept, like sale payments.
 */
class RentalAccounting
{
    use SkipsWhenManual;

    private function enabled(): bool
    {
        return ! $this->shouldSkipAutomation();
    }

    private function ar(): int
    {
        return AccountingHelper::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
    }

    public function invoiceIssued(RentalInvoice $inv, bool $reverse = false): void
    {
        if (! $this->enabled() || (float) $inv->amount <= 0) {
            return;
        }

        $income = AccountingHelper::getOrCreateCoa('4200-RENT', 'Rental Income', 'income');
        $tax = (float) $inv->tax_amount;
        $net = round((float) $inv->amount - $tax, 2);

        $lines = [['coa_id' => $this->ar(), 'debit' => (float) $inv->amount, 'credit' => 0, 'memo' => 'Accounts Receivable']];
        $lines[] = ['coa_id' => $income, 'debit' => 0, 'credit' => $net, 'memo' => 'Rental Income'];
        if ($tax > 0) {
            $lines[] = [
                'coa_id' => AccountingHelper::getOrCreateCoa('2200-TAX', 'Tax Payable', 'liability'),
                'debit' => 0, 'credit' => $tax, 'memo' => 'Tax Payable',
            ];
        }

        $this->post($inv->issue_date, ($reverse ? 'Void ' : '') . 'Rental invoice ' . $inv->invoice_number, 'rental_invoice', $inv->id, $lines, $reverse);
    }

    /** Journal whatever part of the late fee has not been journaled yet (negative = reverse). */
    public function syncLateFee(RentalInvoice $inv): void
    {
        $target = $inv->status === 'void' ? 0.0 : $inv->effectiveLateFee();
        $delta = round($target - (float) $inv->late_fee_posted, 2);
        if (abs($delta) < 0.005) {
            return;
        }

        if ($this->enabled()) {
            $income = AccountingHelper::getOrCreateCoa('4210-LATE', 'Late Fee Income', 'income');
            $amount = abs($delta);
            $this->post(now(), 'Late fee ' . $inv->invoice_number, 'rental_invoice', $inv->id, [
                ['coa_id' => $this->ar(), 'debit' => $amount, 'credit' => 0, 'memo' => 'Late fee receivable'],
                ['coa_id' => $income, 'debit' => 0, 'credit' => $amount, 'memo' => 'Late Fee Income'],
            ], $delta < 0);
        }

        $inv->late_fee_posted = $target;
        $inv->saveQuietly();
    }

    public function paymentReceived(RentalPayment $payment, bool $reverse = false): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) {
            return;
        }

        $fromDeposit = $payment->source === 'deposit';

        // Legacy cash/bank balance, exactly like PaymentSalesController.
        // Money taken from a held deposit never touches the till.
        if (! $fromDeposit && $payment->account_id && ($account = Account::find($payment->account_id))) {
            $account->update(['balance' => $account->balance + ($reverse ? -$amount : $amount)]);
        }

        if (! $this->enabled()) {
            return;
        }

        $cash = $fromDeposit ? $this->depositsCoa() : $this->cashCoa($payment->account_id);

        $this->post($payment->payment_date, ($reverse ? 'Reversal ' : '') . 'Rental payment ' . $payment->payment_number, 'rental_payment', $payment->id, [
            ['coa_id' => $cash, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
            ['coa_id' => $this->ar(), 'debit' => 0, 'credit' => $amount, 'memo' => 'Clear A/R'],
        ], $reverse);
    }

    /**
     * A deposit movement (collected / refunded / retained). "applied" moves
     * are journaled by the deposit-sourced payment they create instead.
     */
    public function depositMoved(RentalDepositTransaction $t, bool $reverse = false): void
    {
        $amount = (float) $t->amount;
        if ($amount <= 0 || $t->type === 'applied') {
            return;
        }

        // The till only moves for cash in (collected) and cash out (refunded).
        if (in_array($t->type, ['collected', 'refunded'], true) && $t->account_id && ($account = Account::find($t->account_id))) {
            $sign = ($t->type === 'collected' ? 1 : -1) * ($reverse ? -1 : 1);
            $account->update(['balance' => $account->balance + $sign * $amount]);
        }

        if (! $this->enabled()) {
            return;
        }

        $deposits = $this->depositsCoa();
        switch ($t->type) {
            case 'collected':
                $lines = [
                    ['coa_id' => $this->cashCoa($t->account_id), 'debit' => $amount, 'credit' => 0, 'memo' => 'Deposit received'],
                    ['coa_id' => $deposits, 'debit' => 0, 'credit' => $amount, 'memo' => 'Customer Deposits'],
                ];
                break;
            case 'refunded':
                $lines = [
                    ['coa_id' => $deposits, 'debit' => $amount, 'credit' => 0, 'memo' => 'Customer Deposits'],
                    ['coa_id' => $this->cashCoa($t->account_id), 'debit' => 0, 'credit' => $amount, 'memo' => 'Deposit refunded'],
                ];
                break;
            default: // retained
                $lines = [
                    ['coa_id' => $deposits, 'debit' => $amount, 'credit' => 0, 'memo' => 'Customer Deposits'],
                    ['coa_id' => AccountingHelper::getOrCreateCoa('4220-DEPKEEP', 'Retained Deposit Income', 'income'), 'debit' => 0, 'credit' => $amount, 'memo' => 'Deposit retained'],
                ];
        }

        $this->post($t->transaction_date, ($reverse ? 'Reversal ' : '') . 'Rental deposit ' . $t->type, 'rental_deposit', $t->id, $lines, $reverse);
    }

    public function expensePaid(RentalExpense $e, bool $reverse = false): void
    {
        $amount = (float) $e->amount;
        if ($amount <= 0) {
            return;
        }
        if ($e->account_id && ($account = Account::find($e->account_id))) {
            $account->update(['balance' => $account->balance + ($reverse ? $amount : -$amount)]);
        }
        if (! $this->enabled()) {
            return;
        }
        $this->post($e->expense_date, ($reverse ? 'Reversal ' : '') . 'Rental expense #' . $e->id, 'rental_expense', $e->id, [
            ['coa_id' => AccountingHelper::getOrCreateCoa('6200-RENTEXP', 'Rental Expenses', 'expense'), 'debit' => $amount, 'credit' => 0, 'memo' => 'Rental expense: ' . $e->category],
            ['coa_id' => $this->cashCoa($e->account_id), 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash Out'],
        ], $reverse);
    }

    private function depositsCoa(): int
    {
        return AccountingHelper::getOrCreateCoa('2300-DEP', 'Customer Deposits', 'liability');
    }

    private function cashCoa($accountId): int
    {
        return AccountingHelper::getOrCreateCoaForLegacyAccount($accountId)
            ?: AccountingHelper::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
    }

    /** Posts a balanced entry; a reversal swaps every debit and credit. */
    private function post($date, string $description, string $type, $id, array $lines, bool $reverse): void
    {
        if ($reverse) {
            $lines = array_map(fn ($l) => array_merge($l, ['debit' => $l['credit'], 'credit' => $l['debit']]), $lines);
        }

        AccountingHelper::createBalancedEntry([
            'journal' => [
                'date' => $date ? \Carbon\Carbon::parse($date)->toDateString() : now()->toDateString(),
                'description' => $description,
                'reference_type' => $type,
                'reference_id' => $id,
            ],
            'lines' => $lines,
        ]);
    }
}
