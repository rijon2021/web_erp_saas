<?php

namespace App\Services\AccountingV2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every operational movement that changes a cash/bank account's balance.
 *
 * Shared by the account statement (what happened on the account) and the
 * reconciliation report (does the General Ledger agree). Each entry carries
 * the document it came from (source_type/source_id) so it can be matched to
 * the journal the AccountingV2 listeners post under the same reference.
 */
class AccountMovements
{
    /**
     * Statement type => journal reference_type the listeners post it under.
     * Types missing here are not journaled automatically.
     */
    public const JOURNAL_REFERENCE = [
        'sale_payment' => 'payment_sale',
        'sale_return_payment' => 'payment_sale_return',
        'purchase_payment' => 'payment_purchase',
        'purchase_return_payment' => 'payment_purchase_return',
        'expense' => 'expense',
        'deposit' => 'deposit',
        'transfer_in' => 'transfer',
        'transfer_out' => 'transfer',
    ];

    /**
     * Unsorted movements for one account. Each entry: date, created_at, type,
     * reference, description, debit (money out), credit (money in),
     * source_type, source_id.
     */
    public static function forAccount(int $accountId): array
    {
        $entries = [];
        $push = function ($row, string $type, string $direction, $amount, string $reference, string $description) use (&$entries) {
            $amount = round((float) $amount, 4);
            if ($amount == 0.0) {
                return;
            }
            $entries[] = [
                'date' => (string) $row->date,
                'created_at' => (string) ($row->created_at ?? ''),
                'type' => $type,
                'reference' => $reference,
                'description' => trim($description),
                'debit' => $direction === 'out' ? $amount : 0.0,
                'credit' => $direction === 'in' ? $amount : 0.0,
                'source_type' => self::JOURNAL_REFERENCE[$type] ?? $type,
                'source_id' => (int) ($row->id ?? 0),
            ];
        };
        $join = fn (array $parts) => implode(' — ', array_values(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '')));

        // Sale payments (money in)
        $rows = DB::table('payment_sales as p')
            ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
            ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 's.Ref as doc_ref', 'c.name as party', 'm.name as method')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'sale_payment', 'in', $r->montant, (string) $r->Ref, $join(['Sale '.$r->doc_ref, $r->party, $r->method]));
        }

        // Sale-return refunds (money out)
        $rows = DB::table('payment_sale_returns as p')
            ->leftJoin('sale_returns as s', 's.id', '=', 'p.sale_return_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
            ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 's.Ref as doc_ref', 'c.name as party', 'm.name as method')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'sale_return_payment', 'out', $r->montant, (string) $r->Ref, $join(['Sale return '.$r->doc_ref, $r->party, $r->method]));
        }

        // Purchase payments (money out)
        $rows = DB::table('payment_purchases as p')
            ->leftJoin('purchases as s', 's.id', '=', 'p.purchase_id')
            ->leftJoin('providers as c', 'c.id', '=', 's.provider_id')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
            ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 's.Ref as doc_ref', 'c.name as party', 'm.name as method')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'purchase_payment', 'out', $r->montant, (string) $r->Ref, $join(['Purchase '.$r->doc_ref, $r->party, $r->method]));
        }

        // Purchase-return refunds received (money in)
        $rows = DB::table('payment_purchase_returns as p')
            ->leftJoin('purchase_returns as s', 's.id', '=', 'p.purchase_return_id')
            ->leftJoin('providers as c', 'c.id', '=', 's.provider_id')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
            ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 's.Ref as doc_ref', 'c.name as party', 'm.name as method')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'purchase_return_payment', 'in', $r->montant, (string) $r->Ref, $join(['Purchase return '.$r->doc_ref, $r->party, $r->method]));
        }

        // Expenses (money out)
        $rows = DB::table('expenses as e')
            ->leftJoin('expense_categories as cat', 'cat.id', '=', 'e.expense_category_id')
            ->whereNull('e.deleted_at')->where('e.account_id', $accountId)
            ->select('e.id', 'e.date', 'e.created_at', 'e.Ref', 'e.amount', 'e.details', 'cat.name as category')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'expense', 'out', $r->amount, (string) $r->Ref, $join(['Expense', $r->category, $r->details]));
        }

        // Deposits (money in)
        $rows = DB::table('deposits as d')
            ->leftJoin('deposit_categories as cat', 'cat.id', '=', 'd.deposit_category_id')
            ->whereNull('d.deleted_at')->where('d.account_id', $accountId)
            ->select('d.id', 'd.date', 'd.created_at', 'd.deposit_ref as Ref', 'd.amount', 'd.description', 'cat.title as category')
            ->get();
        foreach ($rows as $r) {
            $push($r, 'deposit', 'in', $r->amount, (string) $r->Ref, $join(['Deposit', $r->category, $r->description]));
        }

        // Transfers between accounts
        $rows = DB::table('transfer_money as t')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->where('t.from_account_id', $accountId)->orWhere('t.to_account_id', $accountId))
            ->select('t.id', 't.date', 't.created_at', 't.amount', 't.from_account_id', 't.to_account_id', 'fa.account_name as from_name', 'ta.account_name as to_name')
            ->get();
        foreach ($rows as $r) {
            if ((int) $r->from_account_id === $accountId) {
                $push($r, 'transfer_out', 'out', $r->amount, 'TR-'.$r->id, 'Transfer to '.($r->to_name ?: '---'));
            }
            if ((int) $r->to_account_id === $accountId) {
                $push($r, 'transfer_in', 'in', $r->amount, 'TR-'.$r->id, 'Transfer from '.($r->from_name ?: '---'));
            }
        }

        // Payroll (money out)
        if (Schema::hasTable('payrolls')) {
            $rows = DB::table('payrolls as p')
                ->leftJoin('employees as e', 'e.id', '=', 'p.employee_id')
                ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
                ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.amount', DB::raw("TRIM(CONCAT(COALESCE(e.firstname,''), ' ', COALESCE(e.lastname,''))) as party"))
                ->get();
            foreach ($rows as $r) {
                $push($r, 'payroll', 'out', $r->amount, (string) $r->Ref, $join(['Payroll', $r->party]));
            }
        }

        // Service-job payments (money in)
        if (Schema::hasTable('service_job_payments')) {
            $rows = DB::table('service_job_payments as p')
                ->leftJoin('service_jobs as j', 'j.id', '=', 'p.service_job_id')
                ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
                ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
                ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 'j.Ref as doc_ref', 'm.name as method')
                ->get();
            foreach ($rows as $r) {
                $push($r, 'service_job_payment', 'in', $r->montant, (string) $r->Ref, $join(['Service job '.$r->doc_ref, $r->method]));
            }
        }

        // Customer opening-balance payments (money in)
        if (Schema::hasTable('client_opening_balance_payments')) {
            $rows = DB::table('client_opening_balance_payments as p')
                ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
                ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
                ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
                ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 'c.name as party', 'm.name as method')
                ->get();
            foreach ($rows as $r) {
                $push($r, 'client_opening_balance', 'in', $r->montant, (string) $r->Ref, $join(['Customer opening balance', $r->party, $r->method]));
            }
        }

        // Supplier opening-balance payments (money out)
        if (Schema::hasTable('provider_opening_balance_payments')) {
            $rows = DB::table('provider_opening_balance_payments as p')
                ->leftJoin('providers as c', 'c.id', '=', 'p.provider_id')
                ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
                ->whereNull('p.deleted_at')->where('p.account_id', $accountId)
                ->select('p.id', 'p.date', 'p.created_at', 'p.Ref', 'p.montant', 'c.name as party', 'm.name as method')
                ->get();
            foreach ($rows as $r) {
                $push($r, 'provider_opening_balance', 'out', $r->montant, (string) $r->Ref, $join(['Supplier opening balance', $r->party, $r->method]));
            }
        }

        return $entries;
    }
}
