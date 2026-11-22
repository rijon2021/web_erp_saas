<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\CustomerWallet\CustomerWalletService;
use App\Services\SupplierWallet\WalletException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront → My Account → Wallet. The signed-in shopper's customer wallet:
 * balance and history, redeem a gift card, request a withdrawal. Everything
 * answers 404 while the Customer Wallet is off (plan or tenant switch).
 */
class CustomerWalletStoreController extends Controller
{
    public function __construct(private CustomerWalletService $wallets)
    {
    }

    // GET /account/wallet
    public function page()
    {
        if (! $this->wallets->enabled()) {
            abort(404);
        }

        return view('store.account-wallet');
    }

    // GET /my/wallet — balance, last moves, open withdrawal requests, options
    public function summary()
    {
        [$clientId, $error] = $this->customer();
        if ($error) {
            return $error;
        }

        $wallet = $this->wallets->findWallet($clientId);
        $transactions = $wallet
            ? $wallet->transactions()->orderByDesc('id')->limit(10)->get()->map(fn ($t) => $this->present($t))
            : collect();

        $withdrawals = ($wallet && Schema::hasTable('wallet_withdrawals'))
            ? WalletWithdrawal::where('wallet_id', $wallet->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn (WalletWithdrawal $w) => [
                    'id' => $w->id,
                    'amount' => round((float) $w->amount, 2),
                    'status' => $w->status,
                    'method' => $w->method,
                    'note' => in_array($w->status, ['rejected', 'paid'], true) ? $w->note : null,
                    'created_at' => optional($w->created_at)->toDateTimeString(),
                ])
            : collect();

        $pending = ($wallet && Schema::hasTable('wallet_withdrawals'))
            ? (float) WalletWithdrawal::where('wallet_id', $wallet->id)->where('status', 'pending')->sum('amount')
            : 0.0;
        $balance = round((float) ($wallet->balance ?? 0), 2);

        return response()->json([
            'balance' => $balance,
            'available' => max(0, round($balance - $pending, 2)),
            'status' => $wallet->status ?? 'active',
            'withdrawal_enabled' => $this->wallets->withdrawalsEnabled(),
            'min_withdrawal' => $this->wallets->minWithdrawal(),
            'gift_cards_enabled' => $this->wallets->giftCardsAvailable(),
            'checkout_enabled' => $this->wallets->checkoutEnabled(),
            'transactions' => $transactions->values(),
            'withdrawals' => $withdrawals->values(),
        ]);
    }

    // GET /my/wallet/transactions?page=
    public function transactions(Request $request)
    {
        [$clientId, $error] = $this->customer();
        if ($error) {
            return $error;
        }

        $wallet = $this->wallets->findWallet($clientId);
        if (! $wallet) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'pages' => 1]]);
        }
        $per = max(5, min((int) $request->query('per_page', 15), 50));
        $rows = $wallet->transactions()->orderByDesc('id')->paginate($per);

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($t) => $this->present($t))->values(),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
        ]);
    }

    // POST /my/wallet/redeem { code }
    public function redeem(Request $request)
    {
        [$clientId, $error] = $this->customer();
        if ($error) {
            return $error;
        }
        $data = $request->validate(['code' => 'required|string|max:60']);

        try {
            $result = $this->wallets->redeemGiftCard($data['code'], $clientId);
        } catch (WalletException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'success' => true,
            'credited' => round((float) $result['transaction']->amount, 2),
            'balance' => round((float) $result['wallet']->balance, 2),
        ]);
    }

    // POST /my/wallet/withdraw { amount, method, destination }
    public function withdraw(Request $request)
    {
        [$clientId, $error] = $this->customer();
        if ($error) {
            return $error;
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:60',
            'destination' => 'required|string|max:190',
        ]);

        try {
            $w = $this->wallets->requestWithdrawal($clientId, (float) $data['amount'], $data['method'] ?? null, $data['destination']);
        } catch (WalletException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'id' => $w->id], 201);
    }

    /** [clientId, null] for a signed-in shopper with a customer record, else [null, response]. */
    private function customer(): array
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return [null, response()->json(['error' => 'Unauthenticated'], 401)];
        }
        if (! $this->wallets->enabled()) {
            return [null, response()->json(['error' => __('messages.WalletDisabled'), 'code' => 'wallet_disabled'], 404)];
        }
        if (! $user->client_id) {
            return [null, response()->json(['error' => __('messages.CustomerProfileMissing')], 422)];
        }

        return [(int) $user->client_id, null];
    }

    /** Storefront messages are translated by slug; the English text is the fallback. */
    private function fail(WalletException $e)
    {
        $key = 'messages.Wallet_Err_'.$e->slug();
        $text = __($key, $e->context());

        return response()->json([
            'error' => $text !== $key ? $text : $e->getMessage(),
            'code' => $e->slug(),
        ] + $e->context(), 422);
    }

    private function present(WalletTransaction $t): array
    {
        $label = __('messages.Wallet_Src_'.$t->source);

        return [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => round((float) $t->amount, 2),
            'balance_after' => round((float) $t->balance_after, 2),
            'source' => $t->source,
            'source_label' => $label !== 'messages.Wallet_Src_'.$t->source ? $label : ucfirst(str_replace('_', ' ', $t->source)),
            // Internal notes (sale refs, admin text) stay internal except for
            // the self-explanatory ones.
            'note' => in_array($t->source, ['gift_card', 'checkout', 'order_refund'], true) ? $t->note : null,
            'date' => $t->date ? (string) $t->date : optional($t->created_at)->toDateString(),
            'created_at' => optional($t->created_at)->toDateTimeString(),
        ];
    }
}
