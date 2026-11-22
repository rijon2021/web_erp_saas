<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\SupplierWallet\SupplierWalletService;
use App\Services\SupplierWallet\WalletException;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * People → Supplier Wallets. Balances per supplier, the per-supplier ledger
 * (wallet moves + purchase figures) and the cash operations: top-up, refund,
 * manual adjustment, freeze.
 *
 * Permissions: supplier_wallet_view (lists, ledger) and
 * supplier_wallet_manage (money moves, status). Paying a purchase FROM the
 * wallet is a tender on the purchase payment and needs only the usual
 * payment_purchases_* permissions (see PaymentPurchasesController).
 */
class SupplierWalletController extends BaseController
{
    public function __construct(protected SupplierWalletService $wallets)
    {
    }

    /* ------------------------------------------------------------ lists */

    // GET supplier_wallets — every supplier with its wallet balance
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_view', Provider::class);

        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));
        $order = in_array($request->SortField, ['id', 'name', 'code', 'balance', 'status'], true) ? $request->SortField : 'balance';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('providers')
            ->leftJoin('wallets', function ($join) {
                $join->on('wallets.owner_id', '=', 'providers.id')
                    ->where('wallets.owner_type', '=', Wallet::OWNER_PROVIDER)
                    ->whereNull('wallets.deleted_at');
            })
            ->whereNull('providers.deleted_at')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($w) use ($s) {
                    $w->where('providers.name', 'LIKE', "%{$s}%")
                        ->orWhere('providers.code', 'LIKE', "%{$s}%")
                        ->orWhere('providers.phone', 'LIKE', "%{$s}%")
                        ->orWhere('providers.email', 'LIKE', "%{$s}%");
                });
            })
            ->when($request->input('only_with_balance') === '1' || $request->input('only_with_balance') === 'true',
                fn ($q) => $q->where('wallets.balance', '!=', 0))
            ->when($request->filled('status') && in_array($request->status, ['active', 'frozen'], true),
                fn ($q) => $q->where('wallets.status', $request->status));

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $orderCol = in_array($order, ['balance', 'status'], true) ? 'wallets.'.$order : 'providers.'.$order;
        $rows = $query->select(
            'providers.id', 'providers.name', 'providers.code', 'providers.phone', 'providers.email',
            DB::raw('COALESCE(wallets.balance, 0) as balance'),
            DB::raw("COALESCE(wallets.status, 'active') as status"),
            'wallets.updated_at as last_activity'
        )
            ->orderBy($orderCol, $dir)->orderBy('providers.id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()
            ->map(function ($r) {
                $r->balance = round((float) $r->balance, 2);

                return $r;
            });

        $totals = DB::table('wallets')->where('owner_type', Wallet::OWNER_PROVIDER)->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) as positive, COALESCE(SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END),0) as negative, COUNT(*) as wallets, SUM(CASE WHEN status = "frozen" THEN 1 ELSE 0 END) as frozen')
            ->first();

        return response()->json([
            'wallets' => $rows,
            'totalRows' => $totalRows,
            'totals' => [
                'positive' => round((float) ($totals->positive ?? 0), 2),
                'negative' => round((float) ($totals->negative ?? 0), 2),
                'wallets' => (int) ($totals->wallets ?? 0),
                'frozen' => (int) ($totals->frozen ?? 0),
            ],
            'enabled' => $this->wallets->enabled(),
            'allow_negative' => $this->wallets->allowsNegative(),
            'accounts' => Account::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'account_name']),
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }

    // GET supplier_wallets/transactions — every wallet move, all suppliers
    public function transactions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_view', Provider::class);

        return response()->json($this->transactionPage($request, null));
    }

    /* ----------------------------------------------------------- ledger */

    // GET supplier_wallets/{id} — header + KPI figures for the ledger page
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_view', Provider::class);

        $provider = Provider::whereNull('deleted_at')->findOrFail($id);
        $wallet = $this->wallets->findWallet($provider->id);

        $purchases = DB::table('purchases')->whereNull('deleted_at')->where('provider_id', $provider->id)->where('statut', 'received');
        $totalAmount = (float) (clone $purchases)->sum('GrandTotal');
        $totalPaid = (float) (clone $purchases)->sum('paid_amount');
        $returns = DB::table('purchase_returns')->whereNull('deleted_at')->where('provider_id', $provider->id);
        $returnDue = (float) (clone $returns)->sum('GrandTotal') - (float) (clone $returns)->sum('paid_amount');

        $moves = $wallet
            ? DB::table('wallet_transactions')->where('wallet_id', $wallet->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) as debits, COALESCE(SUM(CASE WHEN source='top_up' THEN amount ELSE 0 END),0) as top_ups, COALESCE(SUM(CASE WHEN source='purchase_payment' OR source='opening_balance_payment' THEN amount ELSE 0 END),0) as spent, COUNT(*) as count")
                ->first()
            : null;

        $due = $totalAmount - $totalPaid;
        $balance = $wallet ? (float) $wallet->balance : 0.0;

        return response()->json([
            'provider' => [
                'id' => $provider->id, 'name' => $provider->name, 'code' => $provider->code,
                'email' => $provider->email, 'phone' => $provider->phone,
                'city' => $provider->city, 'country' => $provider->country, 'tax_number' => $provider->tax_number,
                'opening_balance' => (float) ($provider->opening_balance ?? 0),
                'credit_limit' => (float) ($provider->credit_limit ?? 0),
            ],
            'wallet' => [
                'id' => $wallet?->id,
                'balance' => round($balance, 2),
                'status' => $wallet?->status ?? Wallet::STATUS_ACTIVE,
                'credits' => round((float) ($moves->credits ?? 0), 2),
                'debits' => round((float) ($moves->debits ?? 0), 2),
                'top_ups' => round((float) ($moves->top_ups ?? 0), 2),
                'spent' => round((float) ($moves->spent ?? 0), 2),
                'count' => (int) ($moves->count ?? 0),
            ],
            'figures' => [
                'purchases_grand' => round($totalAmount, 2),
                'purchases_paid' => round($totalPaid, 2),
                'purchases_due' => round($due, 2),
                'return_due' => round($returnDue, 2),
                // Opening balance + purchase due - return due, as the suppliers list computes it.
                'net_balance' => round((float) ($provider->opening_balance ?? 0) + $due - $returnDue, 2),
                // What is still owed once the advance is applied (negative = supplier owes us).
                'net_after_wallet' => round((float) ($provider->opening_balance ?? 0) + $due - $returnDue - $balance, 2),
            ],
            'enabled' => $this->wallets->enabled(),
            'allow_negative' => $this->wallets->allowsNegative(),
            'accounts' => Account::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'account_name']),
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }

    // GET supplier_wallets/{id}/transactions
    public function providerTransactions(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_view', Provider::class);
        Provider::whereNull('deleted_at')->findOrFail($id);

        return response()->json($this->transactionPage($request, (int) $id));
    }

    /**
     * GET supplier_wallets/{id}/summary — what a payment form needs to offer
     * "pay from wallet": enabled, balance, status, allow_negative. Open to
     * anyone who may record purchase payments or see suppliers.
     */
    public function summary(Request $request, $id)
    {
        $user = $request->user('api');
        if (! $user || ! ($user->can('create', PaymentPurchase::class) || $user->can('view', Provider::class))) {
            abort(403);
        }

        return response()->json($this->wallets->summaryFor((int) $id));
    }

    /* ------------------------------------------------------- operations */

    // POST supplier_wallets/{id}/top-up
    public function topUp(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_manage', Provider::class);
        $request->validate([
            'amount' => 'required|numeric|gt:0',
            'account_id' => $this->paymentAccountRule(),
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:500',
        ]);
        $provider = Provider::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->topUp($provider, (float) $request->amount, $this->opts($request)));
    }

    // POST supplier_wallets/{id}/refund
    public function refund(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_manage', Provider::class);
        $request->validate([
            'amount' => 'required|numeric|gt:0',
            'account_id' => $this->paymentAccountRule(),
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:500',
        ]);
        $provider = Provider::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->refund($provider, (float) $request->amount, $this->opts($request)));
    }

    // POST supplier_wallets/{id}/adjust  { type: credit|debit, amount, note }
    public function adjust(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_manage', Provider::class);
        $request->validate([
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => 'required|numeric|gt:0',
            'date' => 'nullable|date',
            'note' => 'required|string|max:500',
        ]);
        $provider = Provider::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->adjust($provider, $request->type, (float) $request->amount, $this->opts($request)));
    }

    // POST supplier_wallets/{id}/status  { status: active|frozen }
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'supplier_wallet_manage', Provider::class);
        $request->validate(['status' => ['required', Rule::in(['active', 'frozen'])]]);
        $provider = Provider::whereNull('deleted_at')->findOrFail($id);

        try {
            $wallet = $this->wallets->setStatus($provider, $request->status);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'status' => $wallet->status, 'balance' => round((float) $wallet->balance, 2)]);
    }

    /* ---------------------------------------------------------- helpers */

    protected function opts(Request $request): array
    {
        return [
            'account_id' => $request->filled('account_id') ? (int) $request->account_id : null,
            'payment_method_id' => $request->filled('payment_method_id') ? (int) $request->payment_method_id : null,
            'date' => $request->input('date'),
            'note' => $request->input('note'),
            'user_id' => Auth::id(),
        ];
    }

    protected function guard(callable $fn)
    {
        try {
            $tx = $fn();
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'transaction' => $tx,
            'balance' => round((float) $tx->balance_after, 2),
        ]);
    }

    /**
     * One page of wallet moves, optionally for a single supplier. Filters:
     * type, source, from, to, search (note / reference / supplier).
     */
    protected function transactionPage(Request $request, ?int $providerId): array
    {
        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));
        $order = in_array($request->SortField, ['id', 'date', 'amount', 'balance_after', 'type', 'source'], true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('wallet_transactions as wt')
            ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
            ->leftJoin('providers as p', 'p.id', '=', 'w.owner_id')
            ->leftJoin('users as u', 'u.id', '=', 'wt.user_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'wt.account_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'wt.payment_method_id')
            ->where('w.owner_type', Wallet::OWNER_PROVIDER)
            ->when($providerId !== null, fn ($q) => $q->where('w.owner_id', $providerId))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('w.owner_id', (int) $request->provider_id))
            ->when($request->filled('type') && in_array($request->type, ['credit', 'debit'], true), fn ($q) => $q->where('wt.type', $request->type))
            ->when($request->filled('source') && in_array($request->source, WalletTransaction::PROVIDER_SOURCES, true), fn ($q) => $q->where('wt.source', $request->source))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('wt.date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('wt.date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($w) use ($s) {
                    $w->where('wt.note', 'LIKE', "%{$s}%")
                        ->orWhere('wt.source', 'LIKE', "%{$s}%")
                        ->orWhere('p.name', 'LIKE', "%{$s}%")
                        ->orWhere('u.username', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $sums = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN wt.type='credit' THEN wt.amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN wt.type='debit' THEN wt.amount ELSE 0 END),0) as debits")->first();

        $rows = $query->select(
            'wt.id', 'wt.date', 'wt.type', 'wt.source', 'wt.amount', 'wt.balance_after', 'wt.note',
            'wt.reference_type', 'wt.reference_id', 'wt.created_at',
            'p.id as provider_id', 'p.name as provider_name', 'p.code as provider_code',
            'u.username as user_name', 'a.account_name', 'pm.name as payment_method'
        )
            ->orderBy('wt.'.$order, $dir)->orderBy('wt.id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get();

        $this->attachReferences($rows);

        return [
            'transactions' => $rows,
            'totalRows' => $totalRows,
            'sums' => [
                'credits' => round((float) ($sums->credits ?? 0), 2),
                'debits' => round((float) ($sums->debits ?? 0), 2),
            ],
            'sources' => WalletTransaction::PROVIDER_SOURCES,
        ];
    }

    /** Resolve reference_type/id to a human label (purchase Ref, return Ref…). */
    protected function attachReferences($rows): void
    {
        $byType = [];
        foreach ($rows as $r) {
            if ($r->reference_type && $r->reference_id) {
                $byType[$r->reference_type][] = (int) $r->reference_id;
            }
        }
        $labels = [];
        $lookups = [
            PaymentPurchase::class => ['payment_purchases', 'Ref'],
            \App\Models\PaymentPurchaseReturns::class => ['payment_purchase_returns', 'Ref'],
            \App\Models\ProviderPaymentOpeningBalance::class => ['provider_opening_balance_payments', 'Ref'],
            \App\Models\Purchase::class => ['purchases', 'Ref'],
            \App\Models\PurchaseReturn::class => ['purchase_returns', 'Ref'],
        ];
        foreach ($byType as $type => $ids) {
            if (! isset($lookups[$type])) {
                continue;
            }
            [$table, $col] = $lookups[$type];
            foreach (DB::table($table)->whereIn('id', array_unique($ids))->pluck($col, 'id') as $id => $ref) {
                $labels[$type][(int) $id] = $ref;
            }
        }
        foreach ($rows as $r) {
            $r->reference = $labels[$r->reference_type][(int) $r->reference_id] ?? null;
            $r->reference_kind = $r->reference_type ? class_basename($r->reference_type) : null;
            $r->amount = round((float) $r->amount, helpers::price_decimals());
            $r->balance_after = round((float) $r->balance_after, helpers::price_decimals());
        }
    }
}
