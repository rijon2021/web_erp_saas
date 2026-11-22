<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\GiftCard;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\CustomerWallet\CustomerWalletService;
use App\Services\SupplierWallet\WalletException;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * People → Customer Wallets: balances per customer, the per-customer wallet
 * ledger, cash operations (top-up, cash refund, adjustment, freeze), the
 * wallet settings, storefront withdrawal requests and gift cards.
 *
 * Permissions: customer_wallet_view (lists, ledger), customer_wallet_manage
 * (money moves, status, settings, withdrawals) and gift_cards_manage (issue /
 * edit gift cards, redeem one for a customer). Paying a sale FROM the wallet
 * is the "Customer Wallet" payment method on the usual payment forms and
 * needs only the usual sale-payment permissions.
 */
class CustomerWalletController extends BaseController
{
    public function __construct(protected CustomerWalletService $wallets)
    {
    }

    /* -------------------------------------------------------- dashboard */

    // GET customer_wallets/dashboard — headline figures + recent activity
    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        $walletQ = fn () => DB::table('wallets')->where('owner_type', Wallet::OWNER_CLIENT)->whereNull('deleted_at');
        $txQ = fn () => DB::table('wallet_transactions as wt')->join('wallets as w', 'w.id', '=', 'wt.wallet_id')->where('w.owner_type', Wallet::OWNER_CLIENT);

        $totals = $walletQ()->selectRaw('COUNT(*) as wallets, COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) as positive, COALESCE(SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END),0) as negative, SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) as funded, SUM(CASE WHEN status = "frozen" THEN 1 ELSE 0 END) as frozen')->first();

        $from = Carbon::now()->subDays(29)->toDateString();
        $flows = $txQ()->whereDate('wt.date', '>=', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN wt.type='credit' AND wt.source <> 'reversal' THEN wt.amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN wt.type='debit' AND wt.source <> 'reversal' THEN wt.amount ELSE 0 END),0) as debits, COALESCE(SUM(CASE WHEN wt.source IN ('sale_payment','checkout') THEN wt.amount ELSE 0 END),0) as spent, COALESCE(SUM(CASE WHEN wt.source = 'top_up' THEN wt.amount ELSE 0 END),0) as top_ups")
            ->first();

        $daily = $txQ()->whereDate('wt.date', '>=', $from)
            ->selectRaw("wt.date as day, COALESCE(SUM(CASE WHEN wt.type='credit' THEN wt.amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN wt.type='debit' THEN wt.amount ELSE 0 END),0) as debits")
            ->groupBy('wt.date')->orderBy('wt.date')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'credits' => round((float) $r->credits, 2), 'debits' => round((float) $r->debits, 2)]);

        $bySource = $txQ()->whereDate('wt.date', '>=', $from)->where('wt.source', '<>', 'reversal')
            ->selectRaw('wt.source, wt.type, COALESCE(SUM(wt.amount),0) as total, COUNT(*) as count')
            ->groupBy('wt.source', 'wt.type')->get()
            ->map(fn ($r) => ['source' => $r->source, 'type' => $r->type, 'total' => round((float) $r->total, 2), 'count' => (int) $r->count]);

        $top = DB::table('wallets')->join('clients', 'clients.id', '=', 'wallets.owner_id')
            ->where('wallets.owner_type', Wallet::OWNER_CLIENT)->whereNull('wallets.deleted_at')
            ->where('wallets.balance', '>', 0)
            ->orderByDesc('wallets.balance')->limit(5)
            ->get(['clients.id as client_id', 'clients.name', 'clients.code', 'wallets.balance', 'wallets.status'])
            ->map(function ($r) {
                $r->balance = round((float) $r->balance, 2);

                return $r;
            });

        $recent = $this->transactionPage(new Request(['limit' => 8]), null)['transactions'];

        $withdrawals = Schema::hasTable('wallet_withdrawals')
            ? DB::table('wallet_withdrawals')->whereNull('deleted_at')
                ->selectRaw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) as pending_amount, SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved, COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) as approved_amount, COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) as paid_amount")
                ->first()
            : null;

        $cards = Schema::hasTable('gift_cards')
            ? DB::table('gift_cards')->whereNull('deleted_at')
                ->selectRaw("SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, COALESCE(SUM(CASE WHEN status='active' THEN balance ELSE 0 END),0) as outstanding, SUM(CASE WHEN status='redeemed' THEN 1 ELSE 0 END) as redeemed")
                ->first()
            : null;

        return response()->json([
            'stats' => [
                'wallets' => (int) ($totals->wallets ?? 0),
                'funded' => (int) ($totals->funded ?? 0),
                'frozen' => (int) ($totals->frozen ?? 0),
                'in_circulation' => round((float) ($totals->positive ?? 0), 2),
                'overdrawn' => round((float) ($totals->negative ?? 0), 2),
                'credits_30d' => round((float) ($flows->credits ?? 0), 2),
                'debits_30d' => round((float) ($flows->debits ?? 0), 2),
                'spent_30d' => round((float) ($flows->spent ?? 0), 2),
                'top_ups_30d' => round((float) ($flows->top_ups ?? 0), 2),
                'pending_withdrawals' => (int) ($withdrawals->pending ?? 0),
                'pending_withdrawal_amount' => round((float) ($withdrawals->pending_amount ?? 0), 2),
                'approved_withdrawals' => (int) ($withdrawals->approved ?? 0),
                'approved_withdrawal_amount' => round((float) ($withdrawals->approved_amount ?? 0), 2),
                'withdrawn_total' => round((float) ($withdrawals->paid_amount ?? 0), 2),
                'gift_cards_active' => (int) ($cards->active ?? 0),
                'gift_cards_outstanding' => round((float) ($cards->outstanding ?? 0), 2),
                'gift_cards_redeemed' => (int) ($cards->redeemed ?? 0),
            ],
            'daily' => $daily,
            'by_source' => $bySource,
            'top_wallets' => $top,
            'recent' => $recent,
        ] + $this->flags());
    }

    /* ------------------------------------------------------------ lists */

    // GET customer_wallets — every customer with their wallet balance
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));
        $order = in_array($request->SortField, ['id', 'name', 'code', 'balance', 'status'], true) ? $request->SortField : 'balance';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('clients')
            ->leftJoin('wallets', function ($join) {
                $join->on('wallets.owner_id', '=', 'clients.id')
                    ->where('wallets.owner_type', '=', Wallet::OWNER_CLIENT)
                    ->whereNull('wallets.deleted_at');
            })
            ->whereNull('clients.deleted_at')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($w) use ($s) {
                    $w->where('clients.name', 'LIKE', "%{$s}%")
                        ->orWhere('clients.code', 'LIKE', "%{$s}%")
                        ->orWhere('clients.phone', 'LIKE', "%{$s}%")
                        ->orWhere('clients.email', 'LIKE', "%{$s}%");
                });
            })
            ->when(in_array($request->input('only_with_balance'), ['1', 'true', 1, true], true),
                fn ($q) => $q->where('wallets.balance', '!=', 0))
            ->when($request->filled('status') && in_array($request->status, ['active', 'frozen'], true),
                fn ($q) => $q->where('wallets.status', $request->status));

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $orderCol = in_array($order, ['balance', 'status'], true) ? 'wallets.'.$order : 'clients.'.$order;
        $rows = $query->select(
            'clients.id', 'clients.name', 'clients.code', 'clients.phone', 'clients.email',
            DB::raw('COALESCE(wallets.balance, 0) as balance'),
            DB::raw("COALESCE(wallets.status, 'active') as status"),
            'wallets.updated_at as last_activity'
        )
            ->orderBy($orderCol, $dir)->orderBy('clients.id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()
            ->map(function ($r) {
                $r->balance = round((float) $r->balance, 2);

                return $r;
            });

        $totals = DB::table('wallets')->where('owner_type', Wallet::OWNER_CLIENT)->whereNull('deleted_at')
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
        ] + $this->flags() + $this->formLists());
    }

    // GET customer_wallets/transactions — every customer wallet move
    public function transactions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        return response()->json($this->transactionPage($request, null));
    }

    /* ----------------------------------------------------------- ledger */

    // GET customer_wallets/{id} — header + figures for the customer's wallet page
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        $client = Client::whereNull('deleted_at')->findOrFail($id);
        $wallet = $this->wallets->findWallet($client->id);

        $moves = $wallet
            ? DB::table('wallet_transactions')->where('wallet_id', $wallet->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) as debits, COALESCE(SUM(CASE WHEN source='top_up' THEN amount ELSE 0 END),0) as top_ups, COALESCE(SUM(CASE WHEN source IN ('sale_payment','checkout','opening_balance_payment') THEN amount ELSE 0 END),0) as spent, COALESCE(SUM(CASE WHEN source IN ('return_credit','order_refund') THEN amount ELSE 0 END),0) as refunds, COALESCE(SUM(CASE WHEN source='gift_card' THEN amount ELSE 0 END),0) as gift_cards, COUNT(*) as count")
                ->first()
            : null;

        $sales = DB::table('sales')->whereNull('deleted_at')->where('client_id', $client->id)->where('statut', 'completed');
        $saleDue = (float) (clone $sales)->sum('GrandTotal') - (float) (clone $sales)->sum('paid_amount');
        $returns = DB::table('sale_returns')->whereNull('deleted_at')->where('client_id', $client->id);
        $returnDue = (float) (clone $returns)->sum('GrandTotal') - (float) (clone $returns)->sum('paid_amount');

        $pendingWithdrawals = ($wallet && Schema::hasTable('wallet_withdrawals'))
            ? (float) WalletWithdrawal::where('wallet_id', $wallet->id)->where('status', 'pending')->sum('amount')
            : 0.0;

        return response()->json([
            'client' => [
                'id' => $client->id, 'name' => $client->name, 'code' => $client->code,
                'email' => $client->email, 'phone' => $client->phone, 'city' => $client->city,
                'country' => $client->country,
                'opening_balance' => (float) ($client->opening_balance ?? 0),
            ],
            'wallet' => [
                'id' => $wallet?->id,
                'balance' => round((float) ($wallet->balance ?? 0), 2),
                'status' => $wallet?->status ?? Wallet::STATUS_ACTIVE,
                'credits' => round((float) ($moves->credits ?? 0), 2),
                'debits' => round((float) ($moves->debits ?? 0), 2),
                'top_ups' => round((float) ($moves->top_ups ?? 0), 2),
                'spent' => round((float) ($moves->spent ?? 0), 2),
                'refunds' => round((float) ($moves->refunds ?? 0), 2),
                'gift_cards' => round((float) ($moves->gift_cards ?? 0), 2),
                'count' => (int) ($moves->count ?? 0),
                'pending_withdrawals' => round($pendingWithdrawals, 2),
            ],
            'figures' => [
                'sale_due' => round($saleDue, 2),
                'return_due' => round($returnDue, 2),
                'net_balance' => round((float) ($client->opening_balance ?? 0) + $saleDue - $returnDue, 2),
            ],
        ] + $this->flags() + $this->formLists());
    }

    // GET customer_wallets/{id}/transactions
    public function clientTransactions(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }
        Client::whereNull('deleted_at')->findOrFail($id);

        return response()->json($this->transactionPage($request, (int) $id));
    }

    /**
     * GET customer_wallets/{id}/summary — what a payment form needs to offer
     * the wallet tender (enabled, balance, status, allow_negative, method id).
     * Open to anyone who may record sale payments or see customers.
     */
    public function summary(Request $request, $id)
    {
        $user = $request->user('api');
        if (! $user || ! ($user->can('create', PaymentSale::class) || $user->can('view', Client::class) || $user->can('Sales_pos', \App\Models\Sale::class)
            || $user->can('create', \App\Models\Sale::class) || $user->can('create', \App\Models\PaymentSaleReturns::class))) {
            abort(403);
        }

        return response()->json($this->wallets->summaryFor((int) $id));
    }

    /* ------------------------------------------------------- operations */

    // POST customer_wallets/{id}/top-up
    public function topUp(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate($this->cashRules());
        $client = Client::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->topUp($client, (float) $request->amount, $this->opts($request)));
    }

    // POST customer_wallets/{id}/refund — wallet balance paid back in cash
    public function refund(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate($this->cashRules());
        $client = Client::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->refund($client, (float) $request->amount, $this->opts($request)));
    }

    // POST customer_wallets/{id}/adjust  { type: credit|debit, amount, note }
    public function adjust(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate([
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => 'required|numeric|gt:0',
            'date' => 'nullable|date',
            'note' => 'required|string|max:500',
        ]);
        $client = Client::whereNull('deleted_at')->findOrFail($id);

        return $this->guard(fn () => $this->wallets->adjust($client, $request->type, (float) $request->amount, $this->opts($request)));
    }

    // POST customer_wallets/{id}/status  { status: active|frozen }
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate(['status' => ['required', Rule::in(['active', 'frozen'])]]);
        $client = Client::whereNull('deleted_at')->findOrFail($id);

        try {
            $wallet = $this->wallets->setStatus($client, $request->status);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'status' => $wallet->status, 'balance' => round((float) $wallet->balance, 2)]);
    }

    // POST customer_wallets/{id}/redeem  { code } — redeem a gift card for this customer
    public function redeem(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'gift_cards_manage', Client::class);
        $request->validate(['code' => 'required|string|max:60']);
        $client = Client::whereNull('deleted_at')->findOrFail($id);

        try {
            $result = $this->wallets->redeemGiftCard($request->code, $client->id, Auth::id());
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'credited' => round((float) $result['transaction']->amount, 2),
            'balance' => round((float) $result['wallet']->balance, 2),
        ]);
    }

    /* --------------------------------------------------------- settings */

    // GET customer_wallets/settings
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);

        $s = $this->wallets->settings();

        return response()->json([
            'customer_wallet_enabled' => $s['enabled'],
            'customer_wallet_allow_negative' => $s['allow_negative'],
            'customer_wallet_checkout_enabled' => $s['checkout_enabled'],
            'customer_wallet_refund_destination' => $s['refund_destination'],
            'customer_wallet_withdrawal_enabled' => $s['withdrawal_enabled'],
            'customer_wallet_min_withdrawal' => $s['min_withdrawal'],
            'payment_method_id' => $this->wallets->paymentMethodId(),
            'migrated' => $s['migrated'],
        ] + $this->flags());
    }

    // POST customer_wallets/settings
    public function updateSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);

        $data = $request->validate([
            'customer_wallet_enabled' => 'nullable',
            'customer_wallet_allow_negative' => 'nullable',
            'customer_wallet_checkout_enabled' => 'nullable',
            'customer_wallet_refund_destination' => ['nullable', Rule::in(['wallet', 'original'])],
            'customer_wallet_withdrawal_enabled' => 'nullable',
            'customer_wallet_min_withdrawal' => 'nullable|numeric|min:0',
        ]);
        if (! Schema::hasColumn('settings', 'customer_wallet_enabled')) {
            return response()->json(['success' => false, 'code' => 'not_migrated', 'message' => 'Run the tenant migrations first.'], 422);
        }

        $update = [];
        foreach (['customer_wallet_enabled', 'customer_wallet_allow_negative', 'customer_wallet_checkout_enabled', 'customer_wallet_withdrawal_enabled'] as $flag) {
            if ($request->has($flag)) {
                $update[$flag] = in_array($request->input($flag), ['1', 1, true, 'true'], true) ? 1 : 0;
            }
        }
        if (! empty($data['customer_wallet_refund_destination'])) {
            $update['customer_wallet_refund_destination'] = $data['customer_wallet_refund_destination'];
        }
        if ($request->has('customer_wallet_min_withdrawal')) {
            $update['customer_wallet_min_withdrawal'] = round((float) $request->input('customer_wallet_min_withdrawal'), 2);
        }
        if ($update) {
            Setting::query()->whereNull('deleted_at')->update($update);
            Setting::forgetCurrent();
        }
        $this->wallets->forgetSettings();
        if (array_key_exists('customer_wallet_enabled', $update)) {
            $this->wallets->syncPaymentMethod((bool) $update['customer_wallet_enabled'] && $this->wallets->planAllows());
        }

        return $this->settings($request);
    }

    /* ------------------------------------------------------ withdrawals */

    // GET customer_wallet_withdrawals
    public function withdrawals(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_view', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('wallet_withdrawals as ww')
            ->join('wallets as w', 'w.id', '=', 'ww.wallet_id')
            ->leftJoin('clients as c', 'c.id', '=', 'w.owner_id')
            ->leftJoin('users as u', 'u.id', '=', 'ww.processed_by')
            ->leftJoin('accounts as a', 'a.id', '=', 'ww.account_id')
            ->where('w.owner_type', Wallet::OWNER_CLIENT)
            ->whereNull('ww.deleted_at')
            ->when($request->filled('status') && in_array($request->status, WalletWithdrawal::STATUSES, true), fn ($q) => $q->where('ww.status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($w) use ($s) {
                    $w->where('c.name', 'LIKE', "%{$s}%")->orWhere('c.email', 'LIKE', "%{$s}%")
                        ->orWhere('ww.destination', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $rows = $query->select(
            'ww.id', 'ww.amount', 'ww.status', 'ww.method', 'ww.destination', 'ww.note',
            'ww.created_at', 'ww.processed_at', 'ww.paid_at',
            'c.id as client_id', 'c.name as client_name', 'c.email as client_email',
            'w.balance as wallet_balance', 'u.username as processed_by_name', 'a.account_name'
        )
            ->orderByRaw("CASE ww.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderBy('ww.id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()
            ->map(function ($r) {
                $r->amount = round((float) $r->amount, 2);
                $r->wallet_balance = round((float) $r->wallet_balance, 2);

                return $r;
            });

        return response()->json(['withdrawals' => $rows, 'totalRows' => $totalRows] + $this->flags() + $this->formLists());
    }

    // POST customer_wallet_withdrawals/{id}/approve
    public function approveWithdrawal(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate(['note' => 'nullable|string|max:255']);

        return $this->guardWithdrawal(fn () => $this->wallets->approveWithdrawal((int) $id, Auth::id(), $request->input('note')));
    }

    // POST customer_wallet_withdrawals/{id}/reject
    public function rejectWithdrawal(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate(['note' => 'nullable|string|max:255']);

        return $this->guardWithdrawal(fn () => $this->wallets->rejectWithdrawal((int) $id, Auth::id(), $request->input('note')));
    }

    // POST customer_wallet_withdrawals/{id}/paid  { account_id, payment_method_id, note }
    public function markWithdrawalPaid(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'customer_wallet_manage', Client::class);
        $request->validate([
            'account_id' => $this->paymentAccountRule(),
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'note' => 'nullable|string|max:255',
        ]);

        return $this->guardWithdrawal(fn () => $this->wallets->markWithdrawalPaid(
            (int) $id,
            Auth::id(),
            $request->filled('account_id') ? (int) $request->account_id : null,
            $request->filled('payment_method_id') ? (int) $request->payment_method_id : null,
            $request->input('note')
        ));
    }

    /* ------------------------------------------------------- gift cards */

    // GET gift_cards
    public function giftCards(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'gift_cards_manage', Client::class);
        if ($r = $this->notMigrated()) {
            return $r;
        }

        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));

        $query = GiftCard::query()->with('redeemer:id,name')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('code', 'LIKE', "%{$s}%")->orWhere('name', 'LIKE', "%{$s}%"));
            })
            ->when($request->filled('status') && in_array($request->status, GiftCard::STATUSES, true), function ($q) use ($request) {
                // An active card past its date is shown as expired (the stored
                // status only flips on a redeem attempt), so filter the same way.
                $now = Carbon::now();
                if ($request->status === 'expired') {
                    $q->where(fn ($w) => $w->where('status', 'expired')
                        ->orWhere(fn ($x) => $x->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', $now)));
                } elseif ($request->status === 'active') {
                    $q->where('status', 'active')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $now));
                } else {
                    $q->where('status', $request->status);
                }
            })
            ->when($request->filled('type') && in_array($request->type, GiftCard::TYPES, true), fn ($q) => $q->where('type', $request->type));

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $rows = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get()
            ->map(fn (GiftCard $c) => $this->presentCard($c));

        return response()->json(['gift_cards' => $rows, 'totalRows' => $totalRows] + $this->flags());
    }

    // POST gift_cards  { type, name, amount, expires_at, note, code?, quantity?, prefix? }
    public function storeGiftCard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'gift_cards_manage', Client::class);

        $quantity = max(1, min((int) $request->input('quantity', 1), 500));
        $data = $this->validateCard($request, null, $quantity > 1);

        $created = DB::transaction(function () use ($data, $quantity, $request) {
            $out = [];
            for ($i = 0; $i < $quantity; $i++) {
                $code = ($quantity > 1 || empty($data['code']))
                    ? $this->wallets->generateGiftCardCode((string) $request->input('prefix', 'GC'))
                    : $data['code'];
                $out[] = GiftCard::create([
                    'type' => $data['type'],
                    'code' => $code,
                    'name' => $data['name'] ?? null,
                    'amount' => $data['amount'],
                    'balance' => $data['amount'],
                    'status' => $data['status'] ?? 'active',
                    'expires_at' => $data['expires_at'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ]);
            }

            return $out;
        });

        return response()->json([
            'success' => true,
            'count' => count($created),
            'gift_cards' => collect($created)->map(fn (GiftCard $c) => $this->presentCard($c)),
        ], 201);
    }

    // PUT gift_cards/{id}
    public function updateGiftCard(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'gift_cards_manage', Client::class);
        $card = GiftCard::findOrFail($id);

        // A redeemed card keeps its money fields; only the labels change.
        if ($card->status === 'redeemed') {
            $data = $request->validate([
                'name' => 'nullable|string|max:120',
                'note' => 'nullable|string|max:255',
            ]);
            $card->fill($data)->save();

            return response()->json(['success' => true, 'gift_card' => $this->presentCard($card->fresh('redeemer'))]);
        }

        $data = $this->validateCard($request, $card->id, false);
        $card->fill([
            'type' => $data['type'],
            'code' => ! empty($data['code']) ? $data['code'] : $card->code,
            'name' => $data['name'] ?? null,
            'amount' => $data['amount'],
            'balance' => $data['amount'],
            'status' => $data['status'] ?? $card->status,
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? null,
        ])->save();

        return response()->json(['success' => true, 'gift_card' => $this->presentCard($card->fresh('redeemer'))]);
    }

    // DELETE gift_cards/{id}
    public function destroyGiftCard(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'gift_cards_manage', Client::class);
        $card = GiftCard::findOrFail($id);
        if ($card->status === 'redeemed') {
            return response()->json(['success' => false, 'code' => 'gift_card_redeemed', 'message' => 'A redeemed gift card cannot be deleted.'], 422);
        }
        $card->delete();

        return response()->json(['success' => true]);
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * The wallet tables are not there yet (tenant migrations not run): answer
     * with empty data + flags instead of a 500, so the page can say so.
     */
    protected function notMigrated()
    {
        if (Schema::hasTable('wallets') && Schema::hasTable('wallet_transactions')
            && Schema::hasTable('wallet_withdrawals') && Schema::hasTable('gift_cards')) {
            return null;
        }

        return response()->json([
            'migrated' => false,
            'enabled' => false,
            'plan_allows' => $this->wallets->planAllows(),
            'wallets' => [], 'transactions' => [], 'withdrawals' => [], 'gift_cards' => [],
            'totalRows' => 0,
            'totals' => ['positive' => 0, 'negative' => 0, 'wallets' => 0, 'frozen' => 0],
            'sums' => ['credits' => 0, 'debits' => 0],
            'stats' => (object) [], 'daily' => [], 'by_source' => [], 'top_wallets' => [], 'recent' => [],
            'sources' => WalletTransaction::CLIENT_SOURCES,
        ]);
    }

    protected function flags(): array
    {
        $s = $this->wallets->settings();

        return [
            'enabled' => $this->wallets->enabled(),
            'plan_allows' => $this->wallets->planAllows(),
            'allow_negative' => $s['allow_negative'],
            'withdrawals_enabled' => $s['withdrawal_enabled'],
            'payment_method_id' => $this->wallets->paymentMethodId(),
            'migrated' => $s['migrated'] && Schema::hasTable('wallet_withdrawals') && Schema::hasTable('gift_cards'),
        ];
    }

    protected function formLists(): array
    {
        $walletMethod = $this->wallets->paymentMethodId();

        return [
            'accounts' => Account::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'account_name']),
            // The wallet tender itself is never a way to top up / pay out a wallet.
            'payment_methods' => PaymentMethod::active()->whereNull('deleted_at')
                ->when($walletMethod, fn ($q) => $q->where('id', '!=', $walletMethod))
                ->get(['id', 'name']),
        ];
    }

    protected function cashRules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'account_id' => $this->paymentAccountRule(),
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:500',
        ];
    }

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

    protected function guardWithdrawal(callable $fn)
    {
        try {
            $w = $fn();
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'withdrawal' => $w]);
    }

    protected function validateCard(Request $request, ?int $id, bool $batch): array
    {
        $rules = [
            'type' => ['required', Rule::in(GiftCard::TYPES)],
            'name' => 'nullable|string|max:120',
            'amount' => 'required|numeric|min:0.01',
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'expires_at' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ];
        if (! $batch) {
            // The unique index spans soft-deleted rows too.
            $rules['code'] = ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('gift_cards', 'code')->ignore($id)];
        }
        $data = $request->validate($rules);
        if (! $batch && ! empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }

    protected function presentCard(GiftCard $c): array
    {
        $status = $c->status === 'active' && $c->isExpired() ? 'expired' : $c->status;

        return [
            'id' => $c->id,
            'type' => $c->type,
            'code' => $c->code,
            'name' => $c->name,
            'amount' => round((float) $c->amount, 2),
            'balance' => round((float) $c->balance, 2),
            'status' => $status,
            'expires_at' => optional($c->expires_at)->format('Y-m-d'),
            'note' => $c->note,
            'redeemed_by' => $c->redeemed_by,
            'redeemer_name' => optional($c->redeemer)->name,
            'redeemed_at' => optional($c->redeemed_at)->toDateTimeString(),
            'created_at' => optional($c->created_at)->toDateTimeString(),
        ];
    }

    /**
     * One page of customer wallet moves, optionally for a single customer.
     * Filters: type, source, from, to, client_id, search.
     */
    protected function transactionPage(Request $request, ?int $clientId): array
    {
        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->input('page', 1));
        $order = in_array($request->SortField, ['id', 'date', 'amount', 'balance_after', 'type', 'source'], true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('wallet_transactions as wt')
            ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
            ->leftJoin('clients as c', 'c.id', '=', 'w.owner_id')
            ->leftJoin('users as u', 'u.id', '=', 'wt.user_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'wt.account_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'wt.payment_method_id')
            ->where('w.owner_type', Wallet::OWNER_CLIENT)
            ->when($clientId !== null, fn ($q) => $q->where('w.owner_id', $clientId))
            ->when($request->filled('client_id'), fn ($q) => $q->where('w.owner_id', (int) $request->client_id))
            ->when($request->filled('type') && in_array($request->type, ['credit', 'debit'], true), fn ($q) => $q->where('wt.type', $request->type))
            ->when($request->filled('source') && in_array($request->source, WalletTransaction::CLIENT_SOURCES, true), fn ($q) => $q->where('wt.source', $request->source))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('wt.date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('wt.date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($w) use ($s) {
                    $w->where('wt.note', 'LIKE', "%{$s}%")
                        ->orWhere('wt.source', 'LIKE', "%{$s}%")
                        ->orWhere('c.name', 'LIKE', "%{$s}%")
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
            'c.id as client_id', 'c.name as client_name', 'c.code as client_code',
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
            'sources' => WalletTransaction::CLIENT_SOURCES,
        ];
    }

    /** Resolve reference_type/id to a label (sale Ref, order ref, gift card code…) and an admin link target. */
    protected function attachReferences($rows): void
    {
        $byType = [];
        foreach ($rows as $r) {
            if ($r->reference_type && $r->reference_id) {
                $byType[$r->reference_type][] = (int) $r->reference_id;
            }
        }
        $lookups = [
            \App\Models\Sale::class => ['sales', 'Ref'],
            \App\Models\SaleReturn::class => ['sale_returns', 'Ref'],
            \App\Models\OnlineOrder::class => ['online_orders', 'ref'],
            \App\Models\PaymentOpeningBalance::class => ['client_opening_balance_payments', 'Ref'],
            GiftCard::class => ['gift_cards', 'code'],
            WalletWithdrawal::class => ['wallet_withdrawals', 'id'],
        ];
        $labels = [];
        foreach ($byType as $type => $ids) {
            if (! isset($lookups[$type]) || ! Schema::hasTable($lookups[$type][0])) {
                continue;
            }
            [$table, $col] = $lookups[$type];
            foreach (DB::table($table)->whereIn('id', array_unique($ids))->pluck($col, 'id') as $id => $ref) {
                $labels[$type][(int) $id] = $type === WalletWithdrawal::class ? '#'.$ref : $ref;
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
