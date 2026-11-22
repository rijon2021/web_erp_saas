<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountingV2\AccountMovements;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AccountController extends BaseController
{
    // -------------- Get All Account ---------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        // Check If User Has Permission View  All Records
        $Accounts = Account::where('deleted_at', '=', null)

            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('account_num', 'LIKE', "%{$request->search}%")
                        ->orWhere('account_name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $Accounts->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $account_data = $Accounts->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($account_data as $account) {

            $item['id'] = $account->id;
            $item['account_num'] = $account->account_num;
            $item['account_name'] = $account->account_name;
            $item['balance'] = $account->balance;
            $item['note'] = $account->note;

            $data[] = $item;
        }

        return response()->json([
            'accounts' => $data,
            'totalRows' => $totalRows,
        ]);

    }

    // -------------- Store New Account ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Account::class);

        request()->validate([
            'account_num' => 'required',
            'account_name' => 'required',
            'initial_balance' => 'required',
        ]);

        Account::create([
            'account_num' => $request['account_num'],
            'account_name' => $request['account_name'],
            'initial_balance' => $request['initial_balance'],
            'balance' => $request['initial_balance'],
            'note' => $request['note'],
        ]);

        return response()->json(['success' => true], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -------------- Update Account ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Account::class);
        $Account = Account::findOrFail($id);

        request()->validate([
            'account_num' => 'required',
            'account_name' => 'required',
        ]);

        $Account->update([
            'account_num' => $request['account_num'],
            'account_name' => $request['account_name'],
            'note' => $request['note'],
        ]);

        return response()->json(['success' => true], 200);

    }

    // -------------- Delete Account ---------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Account::class);
        $Account = Account::findOrFail($id);

        $Account->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true], 200);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Account::class);
        $selectedIds = $request->selectedIds;

        foreach ($selectedIds as $account_id) {
            $Account = Account::findOrFail($account_id);

            $Account->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true], 200);
    }

    // -------------- Account statement (all-time ledger) ---------------\\

    /**
     * GET accounts/{id}/statement — every movement that ever touched the
     * account, as a bank-style statement: Date | Type | Reference |
     * Description | Debit (out) | Credit (in) | Balance, with totals.
     *
     * The rows are rebuilt from the tables whose controllers move
     * `accounts.balance` (sale/purchase/return payments, expenses, deposits,
     * transfers, payroll, service-job payments, client/supplier opening-balance
     * payments), so the running balance is derived from the same records and
     * the closing figure can be checked against the stored balance.
     *
     * Query: from/to (Y-m-d, both optional — empty = all time, one side =
     * open-ended), search (reference/description), page/limit, SortType
     * (desc = newest first, the default).
     */
    public function statement(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Account::class);
        $account = Account::whereNull('deleted_at')->findOrFail($id);
        $accountId = (int) $account->id;

        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : null;
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $entries = AccountMovements::forAccount($accountId);

        // Chronological order (date, then insertion order) for the running balance.
        usort($entries, function ($a, $b) {
            return [$a['date'], $a['created_at']] <=> [$b['date'], $b['created_at']];
        });

        $initial = (float) $account->initial_balance;
        $running = $initial;
        $opening = $initial;          // balance carried into the selected range
        $allTimeDebit = 0.0;
        $allTimeCredit = 0.0;
        $inRange = [];
        foreach ($entries as $e) {
            $running += $e['credit'] - $e['debit'];
            $allTimeDebit += $e['debit'];
            $allTimeCredit += $e['credit'];
            if ($from && $e['date'] < $from) {
                $opening = $running;
                continue;
            }
            if ($to && $e['date'] > $to) {
                continue;
            }
            $e['balance'] = round($running, 4);
            $inRange[] = $e;
        }
        $computedClosing = $running;
        $closing = count($inRange) ? end($inRange)['balance'] : $opening;

        $rangeDebit = array_sum(array_column($inRange, 'debit'));
        $rangeCredit = array_sum(array_column($inRange, 'credit'));

        // Search on reference / description / type, after the balance is fixed.
        if ($request->filled('search')) {
            $needle = mb_strtolower((string) $request->search);
            $inRange = array_values(array_filter($inRange, fn ($e) => str_contains(mb_strtolower($e['reference'].' '.$e['description'].' '.$e['type']), $needle)));
        }

        // Newest first by default (bank-statement style); asc = chronological.
        if (strtolower((string) $request->input('SortType', 'desc')) !== 'asc') {
            $inRange = array_reverse($inRange);
        }

        $totalRows = count($inRange);
        $perPage = (int) $request->input('limit', 25);
        if ($perPage <= 0 || $perPage > 100000) {
            $perPage = $totalRows ?: 25;
        }
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($inRange, $offset, $perPage);
        // Ledger lines have no id of their own; number them for the table's row key.
        foreach ($pageRows as $i => &$line) {
            $line['id'] = $offset + $i + 1;
        }
        unset($line);

        return response()->json([
            'account' => [
                'id' => $account->id,
                'account_num' => $account->account_num,
                'account_name' => $account->account_name,
                'initial_balance' => round($initial, 4),
                'balance' => round((float) $account->balance, 4),
            ],
            'rows' => $pageRows,
            'totalRows' => $totalRows,
            'totals' => [
                'opening_balance' => round($opening, 4),
                'debit' => round($rangeDebit, 4),
                'credit' => round($rangeCredit, 4),
                'closing_balance' => round($closing, 4),
                'all_time_debit' => round($allTimeDebit, 4),
                'all_time_credit' => round($allTimeCredit, 4),
                'computed_balance' => round($computedClosing, 4),
                // Non-zero when the stored balance was moved by something the
                // ledger does not know about (manual DB edits, older bugs).
                'unexplained_difference' => round((float) $account->balance - $computedClosing, 4),
            ],
            'from' => $from,
            'to' => $to,
        ]);
    }
}
