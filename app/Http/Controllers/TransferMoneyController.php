<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TransferMoney;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransferMoneyController extends BaseController
{
    // -------------- Get All Transfer Money ---------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', TransferMoney::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        // Check If User Has Permission View  All Records
        $transfers = TransferMoney::with('from_account', 'to_account')->where('deleted_at', '=', null)

            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where(function ($query) use ($request) {
                        return $query->whereHas('from_account', function ($q) use ($request) {
                            $q->where('account_name', 'LIKE', "%{$request->search}%");
                        });
                    })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('to_account', function ($q) use ($request) {
                                $q->where('account_name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $transfers->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $transfer_data = $transfers->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($transfer_data as $transfer) {

            $item['id'] = $transfer->id;
            $item['from_account'] = $transfer['from_account']->account_name;
            $item['to_account'] = $transfer['to_account']->account_name;
            $item['from_account_id'] = $transfer->from_account_id;
            $item['to_account_id'] = $transfer->to_account_id;
            $item['amount'] = $transfer->amount;
            $item['date'] = $transfer->date;

            $data[] = $item;
        }

        $accounts = Account::where('deleted_at', '=', null)->get(['id', 'account_name']);

        return response()->json([
            'transfers' => $data,
            'accounts' => $accounts,
            'totalRows' => $totalRows,
        ]);

    }

    // -------------- Store New TransferMoney ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', TransferMoney::class);

        request()->validate([
            'from_account_id' => 'required|different:to_account_id',
            'to_account_id' => 'required|different:from_account_id',
            'amount' => 'required',
            'date' => 'required',
        ],
            [
                'from_account_id.different' => 'From and to accounts cannot be the same.',
                'to_account_id.different' => 'To and from accounts cannot be the same.',
            ]);

        // Additional check if from_account_id and to_account_id are not the same
        if ($request->from_account_id === $request->to_account_id) {
            return response()->json(['error' => 'Account From and to account cannot be the same.'], 400);
        }

        // 404 early on an unknown account, before any locking.
        Account::findOrFail($request->from_account_id);
        Account::findOrFail($request->to_account_id);

        $insufficient = false;

        \DB::transaction(function () use ($request, &$insufficient) {

            // The sufficiency check has to read the balance, so the row is held
            // for the rest of the transaction. Without the lock two transfers
            // can each see enough funds, both pass the check, and together
            // overdraw the account.
            $from_account = Account::where('id', $request->from_account_id)->lockForUpdate()->first();

            if (! $from_account || $from_account->balance < $request->amount) {
                $insufficient = true;

                return;
            }

            Account::adjustBalance($request->from_account_id, -$request->amount);
            Account::adjustBalance($request->to_account_id, $request->amount);

            TransferMoney::create([
                'from_account_id' => $request['from_account_id'],
                'to_account_id' => $request['to_account_id'],
                'amount' => $request['amount'],
                'date' => $request['date'],
            ]);

        }, 10);

        if ($insufficient) {
            return response()->json(['error' => 'Insufficient balance in the from account.'], 400);
        }

        return response()->json(['success' => true], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -------------- Update TransferMoney ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', TransferMoney::class);

        $transfer = TransferMoney::findOrFail($id);

        // Editing a deleted transfer would adjust balances that destroy() has
        // already put back.
        if ($transfer->deleted_at !== null) {
            return response()->json(['error' => 'This transfer has been deleted.'], 400);
        }

        request()->validate([
            'amount' => 'required',
            'date' => 'required',
        ]);

        // The accounts may be changed on an edit. When the request leaves them
        // out the transfer keeps the ones it already has — previously the new
        // ids were read for the checks below and then silently dropped, so a
        // change appeared to save but never did.
        $fromAccountId = (int) ($request->from_account_id ?: $transfer->from_account_id);
        $toAccountId = (int) ($request->to_account_id ?: $transfer->to_account_id);

        // 404 if any account involved, old or new, no longer exists.
        $involved = array_unique([
            (int) $transfer->from_account_id, (int) $transfer->to_account_id,
            $fromAccountId, $toAccountId,
        ]);
        foreach ($involved as $accountId) {
            Account::findOrFail($accountId);
        }

        // Compared as integers: the request carries strings, so the previous
        // strict comparison never matched and let a same-account edit through.
        if ($fromAccountId === $toAccountId) {
            return response()->json(['error' => 'Account From and to account cannot be the same.'], 400);
        }

        $insufficient = false;

        \DB::transaction(function () use ($request, $transfer, $fromAccountId, $toAccountId, &$insufficient) {

            $accountsChanged = $fromAccountId !== (int) $transfer->from_account_id
                || $toAccountId !== (int) $transfer->to_account_id;

            // Same reasoning as store(): the check reads a balance, so the row
            // stays locked until the adjustment below has been written.
            $from_account = Account::where('id', $fromAccountId)->lockForUpdate()->first();

            // What the source can afford: its balance, plus the old amount only
            // when the source is unchanged, because that money is about to be
            // handed back to it.
            $available = $from_account
                ? $from_account->balance + ($accountsChanged ? 0 : $transfer->amount)
                : 0;

            if (! $from_account || $available < $request->amount) {
                $insufficient = true;

                return;
            }

            if ($accountsChanged) {
                // Undo the whole transfer on the accounts that were actually
                // charged, then apply the new one to the accounts being saved.
                Account::adjustBalance($transfer->from_account_id, $transfer->amount);
                Account::adjustBalance($transfer->to_account_id, -$transfer->amount);
                Account::adjustBalance($fromAccountId, -$request->amount);
                Account::adjustBalance($toAccountId, $request->amount);
            } else {
                // Same accounts: move only the difference, so the balance is
                // never momentarily wrong.
                Account::adjustBalance($fromAccountId, $transfer->amount - $request->amount);
                Account::adjustBalance($toAccountId, $request->amount - $transfer->amount);
            }

            $transfer->update([
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $request['amount'],
                'date' => $request['date'],
            ]);

        }, 10);

        if ($insufficient) {
            return response()->json(['error' => 'Insufficient balance in the from account.'], 400);
        }

        return response()->json(['success' => true], 200);

    }

    // -------------- Delete TransferMoney ---------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', TransferMoney::class);

        $alreadyDeleted = false;

        \DB::transaction(function () use ($id, &$alreadyDeleted) {

            // Locked, because the check and the reversal below must be one step:
            // two concurrent deletes would otherwise both pass the check and
            // reverse the balances twice.
            $transfer = TransferMoney::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($transfer->deleted_at !== null) {
                $alreadyDeleted = true;

                return;
            }

            // 404 if either side of the transfer no longer exists.
            Account::findOrFail($transfer->from_account_id);
            Account::findOrFail($transfer->to_account_id);

            Account::adjustBalance($transfer->from_account_id, $transfer->amount);
            Account::adjustBalance($transfer->to_account_id, -$transfer->amount);

            $transfer->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        if ($alreadyDeleted) {
            return response()->json(['error' => 'This transfer has already been deleted.'], 400);
        }

        return response()->json(['success' => true], 200);
    }
}
