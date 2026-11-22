<?php

namespace App\Http\Controllers\AccountingV2;

use App\Http\Controllers\Controller;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Models\Account;
use App\Services\AccountingV2\AccountingHelper;
use App\Services\AccountingV2\AccountMovements;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class ReportsController extends Controller
{
    use SkipsWhenManual;

    public function trialBalance(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'trial_balance', Account::class);

        if (! Schema::hasTable('acc_journal_entry_lines')) {
            return response()->json(['data' => [], 'totalRows' => 0]);
        }
        $from = $request->get('from');
        $to = $request->get('to');
        $search = trim((string) $request->get('search', ''));
        $page = max(1, (int) $request->get('page', 1));
        $limit = max(1, (int) $request->get('limit', 10));
        $sortField = $request->get('SortField', 'code');
        $sortType = strtolower($request->get('SortType', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['code' => 'c.code', 'name' => 'c.name', 'type' => 'c.type', 'debit' => 'debit', 'credit' => 'credit'];
        $orderBy = $allowedSort[$sortField] ?? 'c.code';

        $base = DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->leftJoin('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted');
        if ($from) {
            $base->where('j.date', '>=', $from);
        }
        if ($to) {
            $base->where('j.date', '<=', $to);
        }
        if ($request->filled('type')) {
            $base->where('c.type', $request->get('type'));
        }
        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('c.code', 'like', "%$search%")->orWhere('c.name', 'like', "%$search%");
            });
        }

        $grouped = $base->select('c.id as coa_id', 'c.code', 'c.name', 'c.type', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))
            ->groupBy('c.id', 'c.code', 'c.name', 'c.type');

        // total groups
        $totalRows = (clone $grouped)->get()->count();

        // pagination + sorting
        $rows = (clone $grouped)
            ->orderBy($orderBy, $sortType)
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    public function profitAndLoss(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'accounting_profit_loss', Account::class);

        if (! Schema::hasTable('acc_journal_entry_lines')) {
            return response()->json(['data' => [], 'totalRows' => 0, 'summary' => ['income' => 0, 'expense' => 0, 'net_profit' => 0]]);
        }
        $from = $request->get('from');
        $to = $request->get('to');
        $search = trim((string) $request->get('search', ''));
        $typeFilter = $request->get('type'); // income|expense optional
        $page = max(1, (int) $request->get('page', 1));
        $limit = max(1, (int) $request->get('limit', 10));
        $sortField = $request->get('SortField', 'code');
        $sortType = strtolower($request->get('SortType', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['code' => 'c.code', 'name' => 'c.name', 'type' => 'c.type', 'amount' => 'amount'];
        $orderBy = $allowedSort[$sortField] ?? 'c.code';

        // Period-only base: date range + posted status. The summary cards are
        // computed from this so they always reflect the whole period, even when
        // the table is narrowed by the type/search filters below.
        $periodBase = DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->leftJoin('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted')
            ->whereIn('c.type', ['income', 'expense']);
        if ($from) {
            $periodBase->where('j.date', '>=', $from);
        }
        if ($to) {
            $periodBase->where('j.date', '<=', $to);
        }

        $base = clone $periodBase;
        if ($typeFilter) {
            $base->where('c.type', $typeFilter);
        }
        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('c.code', 'like', "%$search%")->orWhere('c.name', 'like', "%$search%");
            });
        }

        $amountExpr = "SUM(CASE WHEN c.type='income' THEN (l.credit - l.debit) ELSE (l.debit - l.credit) END)";
        $grouped = $base->select('c.id as coa_id', 'c.code', 'c.name', 'c.type', DB::raw("$amountExpr as amount"))
            ->groupBy('c.id', 'c.code', 'c.name', 'c.type');

        // Total rows (excluding zero-amount rows)
        $totalRows = (clone $grouped)->havingRaw("$amountExpr <> 0")
            ->get()
            ->count();

        // Page rows
        $rows = (clone $grouped)
            ->havingRaw("$amountExpr <> 0")
            ->orderBy($orderBy, $sortType)
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        // Summary
        $income = (float) ((clone $periodBase)->where('c.type', 'income')->select(DB::raw('SUM(l.credit - l.debit) as amount'))->value('amount') ?? 0);
        $expense = (float) ((clone $periodBase)->where('c.type', 'expense')->select(DB::raw('SUM(l.debit - l.credit) as amount'))->value('amount') ?? 0);
        $summary = ['income' => $income, 'expense' => $expense, 'net_profit' => $income - $expense];

        return response()->json([
            'data' => $rows,
            'totalRows' => $totalRows,
            'summary' => $summary,
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'balance_sheet', Account::class);

        // An empty `to` arrives as '' from the page's date input, which would
        // otherwise beat the default and cut the sheet off at nothing.
        $to = $request->filled('to') ? $request->get('to') : Carbon::now()->toDateString();

        if (! Schema::hasTable('acc_journal_entry_lines')) {
            return response()->json([
                'data' => [],
                'totalRows' => 0,
                'date' => $to,
                'assets' => 0,
                'liabilities' => 0,
                'equity' => 0,
                'balance' => 0,
            ]);
        }
        $search = trim((string) $request->get('search', ''));
        $typeFilter = $request->get('type'); // asset|liability|equity optional
        $page = max(1, (int) $request->get('page', 1));
        $limit = max(1, (int) $request->get('limit', 10));
        $sortField = $request->get('SortField', 'code');
        $sortType = strtolower($request->get('SortType', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['code' => 'c.code', 'name' => 'c.name', 'type' => 'c.type', 'amount' => 'amount'];
        $orderBy = $allowedSort[$sortField] ?? 'c.code';

        // As-of base: everything posted up to the cut-off date. The three
        // statement totals are computed from this so they always cover the
        // whole sheet, even when the detail table below is narrowed by the
        // type/search filters.
        $asOfBase = DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->leftJoin('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted')
            ->where('j.date', '<=', $to);

        $assets = (clone $asOfBase)->where('c.type', 'asset')->select(DB::raw('SUM(l.debit - l.credit) as amount'))->value('amount') ?? 0;
        $liabilities = (clone $asOfBase)->where('c.type', 'liability')->select(DB::raw('SUM(l.credit - l.debit) as amount'))->value('amount') ?? 0;
        $equity = (clone $asOfBase)->where('c.type', 'equity')->select(DB::raw('SUM(l.credit - l.debit) as amount'))->value('amount') ?? 0;

        // Income and expense are never closed into an equity account until the
        // year-end close, so the profit to date sits outside the three sections
        // and the sheet can't balance. Carry it into equity as its own line.
        $earnings = (float) ((clone $asOfBase)->whereIn('c.type', ['income', 'expense'])
            ->select(DB::raw('SUM(l.credit - l.debit) as amount'))->value('amount') ?? 0);
        $equity = (float) $equity + $earnings;

        // Per-account detail lines, same shape as the profit & loss report.
        $base = (clone $asOfBase)->whereIn('c.type', ['asset', 'liability', 'equity']);
        if ($typeFilter) {
            $base->where('c.type', $typeFilter);
        }
        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('c.code', 'like', "%$search%")->orWhere('c.name', 'like', "%$search%");
            });
        }

        // Assets carry a debit balance, liabilities and equity a credit one, so
        // each side is signed to read positive on a healthy sheet.
        $amountExpr = "SUM(CASE WHEN c.type='asset' THEN (l.debit - l.credit) ELSE (l.credit - l.debit) END)";
        $grouped = $base->select('c.id as coa_id', 'c.code', 'c.name', 'c.type', DB::raw("$amountExpr as amount"))
            ->groupBy('c.id', 'c.code', 'c.name', 'c.type');

        // Total rows (excluding zero-amount rows)
        $totalRows = (clone $grouped)->havingRaw("$amountExpr <> 0")
            ->get()
            ->count();

        // Page rows
        $rows = (clone $grouped)
            ->havingRaw("$amountExpr <> 0")
            ->orderBy($orderBy, $sortType)
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        // The earnings line follows the real accounts, on the last page.
        $showEarnings = abs($earnings) >= 0.005 && in_array($typeFilter, [null, '', 'equity'], true)
            && ($search === '' || stripos('current year earnings', $search) !== false);
        if ($showEarnings) {
            $totalRows++;
            if ($page >= (int) ceil($totalRows / $limit)) {
                $rows->push((object) [
                    'coa_id' => 'current_earnings',
                    'code' => '-',
                    'name' => 'Current Year Earnings',
                    'type' => 'equity',
                    'amount' => $earnings,
                ]);
            }
        }

        // Inventory account vs. the stock on hand. Only meaningful for a
        // sheet as of today: stock quantities are live, not historical.
        $inventory = null;
        if ($to >= Carbon::now()->toDateString()) {
            $stockValue = AccountingHelper::inventoryStockValue();
            $bookValue = AccountingHelper::inventoryBookValue();
            $inventory = [
                'stock_value' => round($stockValue, 2),
                'book_value' => round($bookValue, 2),
                'gap' => round($stockValue - $bookValue, 2),
                'can_true_up' => Gate::forUser($request->user('api'))->allows('journal_entries', Account::class),
            ];
        }

        return response()->json([
            'data' => $rows,
            'totalRows' => $totalRows,
            'date' => $to,
            'assets' => (float) $assets,
            'liabilities' => (float) $liabilities,
            'equity' => (float) $equity,
            'inventory' => $inventory,
            'balance' => (float) $assets - ((float) $liabilities + (float) $equity),
        ]);
    }

    /**
     * POST reports/inventory-true-up — post one entry bringing the Inventory
     * account to the value of the stock on hand (see
     * AccountingHelper::postInventoryTrueUp()).
     */
    public function inventoryTrueUp(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);

        if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
            return response()->json(['message' => 'Accounting is not set up.'], 422);
        }

        $posted = AccountingHelper::postInventoryTrueUp();

        return response()->json(['success' => true, 'posted' => $posted]);
    }

    /**
     * Every posted line hitting one chart account, with an opening balance
     * carried in from before `from` and a running balance per line. Assets and
     * expenses run on the debit side, everything else on the credit side, so
     * the balance reads positive for a normal account.
     */
    public function accountLedger(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $coaId = (int) $request->get('coa_id');
        $from = $request->filled('from') ? $request->get('from') : null;
        $to = $request->filled('to') ? $request->get('to') : null;
        $page = max(1, (int) $request->get('page', 1));
        $limit = max(1, (int) $request->get('limit', 10));

        $account = Schema::hasTable('acc_chart_of_accounts')
            ? DB::table('acc_chart_of_accounts')->where('id', $coaId)->first(['id', 'code', 'name', 'type'])
            : null;
        if (! $account || ! Schema::hasTable('acc_journal_entry_lines')) {
            return response()->json([
                'account' => $account, 'data' => [], 'totalRows' => 0,
                'opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0,
            ]);
        }

        $sign = in_array($account->type, ['asset', 'expense'], true) ? 1 : -1;

        $posted = DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')
            ->where('l.coa_id', $coaId);

        $opening = $from
            ? (float) ((clone $posted)->where('j.date', '<', $from)->select(DB::raw("SUM(l.debit - l.credit) * $sign as amount"))->value('amount') ?? 0)
            : 0.0;

        $period = (clone $posted)
            ->when($from, fn ($q) => $q->where('j.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('j.date', '<=', $to));

        $totals = (clone $period)->select(DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'), DB::raw('COUNT(*) as n'))->first();
        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);

        $ordered = (clone $period)->orderBy('j.date')->orderBy('j.id')->orderBy('l.id');
        $offset = ($page - 1) * $limit;

        // Balance brought forward to the first line of this page.
        $running = $opening;
        if ($offset > 0) {
            foreach ((clone $ordered)->take($offset)->get(['l.debit', 'l.credit']) as $b) {
                $running += ((float) $b->debit - (float) $b->credit) * $sign;
            }
        }

        $rows = (clone $ordered)->skip($offset)->take($limit)->get([
            'l.id', 'j.id as journal_id', 'j.date', 'j.description', 'j.reference_type', 'j.reference_id',
            'l.memo', 'l.debit', 'l.credit',
        ])->map(function ($r) use (&$running, $sign) {
            $running += ((float) $r->debit - (float) $r->credit) * $sign;
            $r->debit = (float) $r->debit;
            $r->credit = (float) $r->credit;
            $r->balance = round($running, 2);

            return $r;
        });

        return response()->json([
            'account' => $account,
            'data' => $rows,
            'totalRows' => (int) ($totals->n ?? 0),
            'opening' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'closing' => $opening + ($debit - $credit) * $sign,
        ]);
    }

    public function taxSummary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'accounting_tax_report', Account::class);

        // Compute from legacy sales/purchases including returns
        $from = $request->get('from');
        $to = $request->get('to');

        $sales = 0.0;
        $outputTax = 0.0;
        $saleReturns = 0.0;
        $saleReturnTax = 0.0;
        $purchases = 0.0;
        $inputTax = 0.0;
        $purchaseReturns = 0.0;
        $purchaseReturnTax = 0.0;

        // Calculate Sales (Output Tax)
        if (Schema::hasTable('sales')) {
            $q = DB::table('sales')
                ->whereNull('deleted_at')
                ->where('statut', '!=', 'pending'); // Exclude draft/pending sales
            if ($from) {
                $q->where('date', '>=', $from);
            }
            if ($to) {
                $q->where('date', '<=', $to);
            }
            $sales = (float) ($q->sum('GrandTotal') ?? 0);
            $outputTax = (float) ($q->sum('TaxNet') ?? 0);
        }

        // Calculate Sale Returns (Reduce Output Tax)
        if (Schema::hasTable('sale_returns')) {
            $q = DB::table('sale_returns')
                ->whereNull('deleted_at')
                ->where('statut', '!=', 'pending');
            if ($from) {
                $q->where('date', '>=', $from);
            }
            if ($to) {
                $q->where('date', '<=', $to);
            }
            $saleReturns = (float) ($q->sum('GrandTotal') ?? 0);
            $saleReturnTax = (float) ($q->sum('TaxNet') ?? 0);
        }

        // Calculate Purchases (Input Tax)
        if (Schema::hasTable('purchases')) {
            $q = DB::table('purchases')
                ->whereNull('deleted_at')
                ->where('statut', '!=', 'pending'); // Exclude draft/pending purchases
            if ($from) {
                $q->where('date', '>=', $from);
            }
            if ($to) {
                $q->where('date', '<=', $to);
            }
            $purchases = (float) ($q->sum('GrandTotal') ?? 0);
            $inputTax = (float) ($q->sum('TaxNet') ?? 0);
        }

        // Calculate Purchase Returns (Reduce Input Tax)
        if (Schema::hasTable('purchase_returns')) {
            $q = DB::table('purchase_returns')
                ->whereNull('deleted_at')
                ->where('statut', '!=', 'pending');
            if ($from) {
                $q->where('date', '>=', $from);
            }
            if ($to) {
                $q->where('date', '<=', $to);
            }
            $purchaseReturns = (float) ($q->sum('GrandTotal') ?? 0);
            $purchaseReturnTax = (float) ($q->sum('TaxNet') ?? 0);
        }

        // Net calculations (sales - returns)
        $netSales = $sales - $saleReturns;
        $netOutputTax = $outputTax - $saleReturnTax;
        $netPurchases = $purchases - $purchaseReturns;
        $netInputTax = $inputTax - $purchaseReturnTax;

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'sales' => $sales,
            'sale_returns' => $saleReturns,
            'taxable_sales' => $netSales,
            'output_tax' => $netOutputTax,
            'purchases' => $purchases,
            'purchase_returns' => $purchaseReturns,
            'taxable_purchases' => $netPurchases,
            'input_tax' => $netInputTax,
            'net_tax' => $netOutputTax - $netInputTax,
        ]);
    }

    /**
     * GET reports/reconciliation — each cash/bank account's operational
     * balance (accounts.balance, moved by the POS, payments, expenses...)
     * next to the General Ledger balance of its linked COA account.
     *
     * Read-only by design: the two ledgers are kept separate and this report
     * only shows where they disagree. Nothing here writes to either side.
     */
    public function reconciliation(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'accounting_reconciliation', Account::class);

        $accounts = DB::table('accounts')->whereNull('deleted_at')->orderBy('account_name')
            ->get(['id', 'account_num', 'account_name', 'initial_balance', 'balance']);

        $installed = Schema::hasTable('acc_chart_of_accounts') && Schema::hasTable('acc_journal_entry_lines');
        $coaByAccount = $installed
            ? DB::table('acc_chart_of_accounts')->whereNotNull('account_id')->pluck('id', 'account_id')
            : collect();
        $glByCoa = $installed && $coaByAccount->isNotEmpty()
            ? $this->postedNetByCoa($coaByAccount->values()->all())
            : [];

        $rows = $accounts->map(function ($a) use ($coaByAccount, $glByCoa) {
            $coaId = $coaByAccount[$a->id] ?? null;
            $operational = round((float) $a->balance, 2);
            $gl = $coaId ? round($glByCoa[$coaId] ?? 0.0, 2) : 0.0;
            $difference = round($operational - $gl, 2);

            return [
                'account_id' => (int) $a->id,
                'account_num' => $a->account_num,
                'account_name' => $a->account_name,
                'coa_id' => $coaId ? (int) $coaId : null,
                'operational_balance' => $operational,
                'gl_balance' => $gl,
                'difference' => $difference,
                'reconciled' => abs($difference) < 0.01,
            ];
        })->values();

        // Payments posted without an account land on the generic Cash COA.
        // They belong to no account row, so they are reported separately.
        $unassigned = 0.0;
        if ($installed) {
            $cashCoaId = DB::table('acc_chart_of_accounts')->where('code', '1000-CASH')->value('id');
            if ($cashCoaId) {
                $unassigned = round($this->postedNetByCoa([$cashCoaId])[$cashCoaId] ?? 0.0, 2);
            }
        }

        return response()->json([
            'data' => $rows,
            'totals' => [
                'operational_balance' => round($rows->sum('operational_balance'), 2),
                'gl_balance' => round($rows->sum('gl_balance'), 2),
                'difference' => round($rows->sum('difference'), 2),
                'unreconciled' => $rows->where('reconciled', false)->count(),
            ],
            'unassigned_cash_gl' => $unassigned,
            'auto_journals' => $this->autoJournalsEnabled(),
            'installed' => $installed,
        ]);
    }

    /**
     * GET reports/reconciliation/{accountId} — every movement on one account
     * matched to the journal posted for the same document, plus the items
     * that explain the difference.
     *
     * Movement statuses: matched, missing (no journal), mismatch (journal
     * amount differs), not_journaled (a type the listeners never post, e.g.
     * payroll). Journal-only items (ledger_only) are GL postings on the
     * account with no live movement behind them — typically a document that
     * was deleted without its journal being reversed, or a manual entry.
     *
     * Query: status (issues = everything but matched, the default; all; or
     * one status), search, page/limit.
     */
    public function reconciliationDetail(Request $request, $accountId)
    {
        $this->authorizeForUser($request->user('api'), 'accounting_reconciliation', Account::class);

        $account = DB::table('accounts')->whereNull('deleted_at')->where('id', (int) $accountId)
            ->first(['id', 'account_num', 'account_name', 'initial_balance', 'balance']);
        if (! $account) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $installed = Schema::hasTable('acc_chart_of_accounts') && Schema::hasTable('acc_journal_entry_lines');
        $coaId = $installed ? DB::table('acc_chart_of_accounts')->where('account_id', $account->id)->value('id') : null;

        // Net GL posting per source document on this account's COA.
        $ledger = [];
        if ($coaId) {
            $referenced = DB::table('acc_journal_entry_lines as l')
                ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                ->where('j.status', 'posted')->where('l.coa_id', $coaId)->whereNotNull('j.reference_type')
                ->groupBy('j.reference_type', 'j.reference_id')
                ->select('j.reference_type', 'j.reference_id', DB::raw('MIN(j.date) as date'), DB::raw('MAX(j.description) as description'),
                    DB::raw('MIN(j.id) as journal_id'), DB::raw('SUM(l.debit) - SUM(l.credit) as net'))
                ->get();
            foreach ($referenced as $g) {
                $ledger[$g->reference_type.':'.(int) $g->reference_id] = $g;
            }
            $manual = DB::table('acc_journal_entry_lines as l')
                ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                ->where('j.status', 'posted')->where('l.coa_id', $coaId)->whereNull('j.reference_type')
                ->groupBy('j.id', 'j.date', 'j.description')
                ->select(DB::raw('NULL as reference_type'), DB::raw('NULL as reference_id'), 'j.date', 'j.description',
                    'j.id as journal_id', DB::raw('SUM(l.debit) - SUM(l.credit) as net'))
                ->get();
            foreach ($manual as $g) {
                $ledger['journal:'.(int) $g->journal_id] = $g;
            }
        }

        $movements = AccountMovements::forAccount((int) $account->id);
        $rows = [];
        $sum = ['missing' => 0.0, 'mismatch' => 0.0, 'not_journaled' => 0.0, 'ledger_only' => 0.0];
        $computed = (float) $account->initial_balance;

        foreach ($movements as $m) {
            $expected = round($m['credit'] - $m['debit'], 2);
            $computed += $expected;

            if (! isset(AccountMovements::JOURNAL_REFERENCE[$m['type']])) {
                $status = 'not_journaled';
                $gl = 0.0;
            } else {
                $key = $m['source_type'].':'.$m['source_id'];
                $gl = isset($ledger[$key]) ? round((float) $ledger[$key]->net, 2) : 0.0;
                unset($ledger[$key]);
                $status = abs($expected - $gl) < 0.01 ? 'matched' : (abs($gl) < 0.01 ? 'missing' : 'mismatch');
            }
            if ($status !== 'matched') {
                $sum[$status] += $expected - $gl;
            }

            $rows[] = [
                'date' => $m['date'], 'created_at' => $m['created_at'], 'type' => $m['type'],
                'reference' => $m['reference'], 'description' => $m['description'],
                'expected' => $expected, 'gl' => $gl, 'difference' => round($expected - $gl, 2),
                'status' => $status, 'journal_id' => null,
            ];
        }

        // Whatever is left in the ledger has no live movement behind it.
        foreach ($ledger as $key => $g) {
            $net = round((float) $g->net, 2);
            if (abs($net) < 0.01) {
                continue;
            }
            $sum['ledger_only'] -= $net;
            $rows[] = [
                'date' => (string) $g->date, 'created_at' => '', 'type' => $g->reference_type ?: 'manual_journal',
                'reference' => $g->reference_type ? $key : 'JE-'.$g->journal_id, 'description' => (string) $g->description,
                'expected' => 0.0, 'gl' => $net, 'difference' => -$net,
                'status' => 'ledger_only', 'journal_id' => (int) $g->journal_id,
            ];
        }

        $operational = round((float) $account->balance, 2);
        $glBalance = $coaId ? round($this->postedNetByCoa([$coaId])[$coaId] ?? 0.0, 2) : 0.0;
        $difference = round($operational - $glBalance, 2);
        $opening = round((float) $account->initial_balance, 2);
        $drift = round($operational - $computed, 2);
        $explained = $opening + $drift + array_sum($sum);

        $counts = array_count_values(array_column($rows, 'status'));

        $filter = (string) $request->input('status', 'issues');
        if ($filter === 'issues') {
            $rows = array_filter($rows, fn ($r) => $r['status'] !== 'matched');
        } elseif ($filter !== 'all') {
            $rows = array_filter($rows, fn ($r) => $r['status'] === $filter);
        }
        if ($request->filled('search')) {
            $needle = mb_strtolower((string) $request->search);
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['reference'].' '.$r['description'].' '.$r['type']), $needle));
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['date'], $b['created_at']] <=> [$a['date'], $a['created_at']]);

        $total = count($rows);
        $limit = max(1, min(500, (int) $request->input('limit', 25)));
        $page = max(1, (int) $request->input('page', 1));

        return response()->json([
            'account' => [
                'id' => (int) $account->id, 'account_num' => $account->account_num,
                'account_name' => $account->account_name, 'coa_id' => $coaId ? (int) $coaId : null,
            ],
            'summary' => [
                'operational_balance' => $operational,
                'gl_balance' => $glBalance,
                'difference' => $difference,
                'items' => [
                    'opening_balance' => $opening,
                    'balance_drift' => $drift,
                    'missing' => round($sum['missing'], 2),
                    'mismatch' => round($sum['mismatch'], 2),
                    'not_journaled' => round($sum['not_journaled'], 2),
                    'ledger_only' => round($sum['ledger_only'], 2),
                ],
                'unexplained' => round($difference - $explained, 2),
            ],
            'counts' => [
                'matched' => $counts['matched'] ?? 0,
                'missing' => $counts['missing'] ?? 0,
                'mismatch' => $counts['mismatch'] ?? 0,
                'not_journaled' => $counts['not_journaled'] ?? 0,
                'ledger_only' => $counts['ledger_only'] ?? 0,
            ],
            'data' => array_slice($rows, ($page - 1) * $limit, $limit),
            'totalRows' => $total,
            'auto_journals' => $this->autoJournalsEnabled(),
        ]);
    }

    /** Posted debit-minus-credit per COA id (asset sign: money in is positive). */
    protected function postedNetByCoa(array $coaIds): array
    {
        return DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')->whereIn('l.coa_id', $coaIds)
            ->groupBy('l.coa_id')
            ->select('l.coa_id', DB::raw('SUM(l.debit) - SUM(l.credit) as net'))
            ->pluck('net', 'coa_id')->map(fn ($v) => (float) $v)->all();
    }

    /** Same switch the journal listeners obey. */
    protected function autoJournalsEnabled(): bool
    {
        return $this->accountingAutomationEnabled();
    }
}
