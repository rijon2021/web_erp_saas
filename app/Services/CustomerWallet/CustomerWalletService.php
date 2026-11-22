<?php

namespace App\Services\CustomerWallet;

use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Models\Account;
use App\Models\Client;
use App\Models\GiftCard;
use App\Models\OnlineOrder;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\AccountingV2\AccountingHelper;
use App\Services\SupplierWallet\WalletException;
use App\Services\TenantLimitsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Server-authoritative customer wallet ledger.
 *
 * A customer wallet is money the business holds FOR a customer: cash
 * top-ups, sale-return refunds and online-order refunds credited to the
 * wallet, redeemed gift cards and manual corrections. It is spent by paying
 * sales with the "Customer Wallet" payment method (back office, POS, pay due)
 * and by storefront orders paid with the wallet, and paid back out through
 * cash refunds and approved withdrawal requests.
 *
 * Wallets share the owner-polymorphic `wallets` / `wallet_transactions`
 * tables with the Supplier Wallet (owner_type = 'client' here). Every balance
 * change goes through credit()/debit() under a row lock, so each move writes
 * a wallet_transactions row with the running balance.
 *
 * Accounting (Accounting V2, when journals are automated), against the
 * liability 2310-CUSTWAL "Customer Wallet Balances":
 *   top-up             Dr cash account / Cr 2310
 *   cash refund        Dr 2310 / Cr cash account
 *   withdrawal paid    Dr 2310 / Cr cash account (posted when marked paid)
 *   adjustment, gift   Dr|Cr 2310 against retained earnings
 *   sale payment       posted by the payment row itself:
 *                      AccountingHelper::fromPaymentSale settles against
 *                      2310 instead of cash when the row carries
 *                      wallet_transaction_id (Dr 2310 / Cr A/R)
 *   storefront charge  nothing until the order becomes a Sale; its payment
 *                      row then carries the checkout transaction id
 *   return credits     no journal, like cash sale-return payments and
 *                      online-order refunds today
 */
class CustomerWalletService
{
    use SkipsWhenManual;

    public const COA_CODE = '2310-CUSTWAL';

    public const COA_NAME = 'Customer Wallet Balances';

    public const FEATURE = 'customer_wallet';

    /** Name the tender gets when it is first created (it may be renamed). */
    public const METHOD_NAME = 'Customer Wallet';

    protected ?array $settingsMemo = null;

    protected ?bool $enabledMemo = null;

    /** false = not looked up yet. */
    protected int|null|false $methodIdMemo = false;

    /* --------------------------------------------------------- settings */

    /** Every customer-wallet setting in one read (safe while not migrated). */
    public function settings(): array
    {
        if ($this->settingsMemo !== null) {
            return $this->settingsMemo;
        }
        $defaults = [
            'enabled' => false,
            'allow_negative' => false,
            'checkout_enabled' => true,
            'refund_destination' => 'original',
            'withdrawal_enabled' => false,
            'min_withdrawal' => 0.0,
            'payment_method_id' => null,
            'migrated' => false,
        ];
        try {
            if (! Schema::hasColumn('settings', 'customer_wallet_enabled')) {
                return $this->settingsMemo = $defaults;
            }
            $row = Setting::query()->whereNull('deleted_at')->first([
                'customer_wallet_enabled', 'customer_wallet_allow_negative', 'customer_wallet_checkout_enabled',
                'customer_wallet_refund_destination', 'customer_wallet_withdrawal_enabled',
                'customer_wallet_min_withdrawal', 'customer_wallet_payment_method_id',
            ]);

            return $this->settingsMemo = [
                'enabled' => (bool) ($row->customer_wallet_enabled ?? false),
                'allow_negative' => (bool) ($row->customer_wallet_allow_negative ?? false),
                'checkout_enabled' => (bool) ($row->customer_wallet_checkout_enabled ?? true),
                'refund_destination' => in_array($row->customer_wallet_refund_destination ?? null, ['wallet', 'original'], true)
                    ? $row->customer_wallet_refund_destination : 'original',
                'withdrawal_enabled' => (bool) ($row->customer_wallet_withdrawal_enabled ?? false),
                'min_withdrawal' => round((float) ($row->customer_wallet_min_withdrawal ?? 0), 2),
                'payment_method_id' => $row && $row->customer_wallet_payment_method_id ? (int) $row->customer_wallet_payment_method_id : null,
                'migrated' => true,
            ];
        } catch (\Throwable $e) {
            return $this->settingsMemo = $defaults;
        }
    }

    public function forgetSettings(): void
    {
        $this->settingsMemo = null;
        $this->enabledMemo = null;
        $this->methodIdMemo = false;
    }

    /** Settings switch AND plan feature AND tables present. */
    public function enabled(): bool
    {
        if ($this->enabledMemo !== null) {
            return $this->enabledMemo;
        }
        try {
            $on = $this->settings()['enabled'] && $this->planAllows() && Schema::hasTable('wallets');
        } catch (\Throwable $e) {
            $on = false;
        }

        return $this->enabledMemo = $on;
    }

    public function planAllows(): bool
    {
        try {
            return app(TenantLimitsService::class)->hasFeature(self::FEATURE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function allowsNegative(): bool
    {
        return $this->settings()['allow_negative'];
    }

    public function checkoutEnabled(): bool
    {
        return $this->enabled() && $this->settings()['checkout_enabled'];
    }

    public function withdrawalsEnabled(): bool
    {
        return $this->enabled() && $this->settings()['withdrawal_enabled'] && Schema::hasTable('wallet_withdrawals');
    }

    public function minWithdrawal(): float
    {
        return $this->settings()['min_withdrawal'];
    }

    public function giftCardsAvailable(): bool
    {
        return $this->enabled() && Schema::hasTable('gift_cards');
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new WalletException('Customer wallet is not enabled.', 'wallet_disabled');
        }
    }

    /* --------------------------------------------------- payment method */

    /** Id of the "Customer Wallet" tender, or null when it does not exist. */
    public function paymentMethodId(): ?int
    {
        if ($this->methodIdMemo !== false) {
            return $this->methodIdMemo;
        }
        $id = $this->settings()['payment_method_id'];
        if (! $id) {
            return $this->methodIdMemo = null;
        }
        try {
            $exists = PaymentMethod::query()->whereKey($id)->whereNull('deleted_at')->exists();
        } catch (\Throwable $e) {
            return null;
        }

        return $this->methodIdMemo = $exists ? $id : null;
    }

    /**
     * The tender's id, creating it when missing, active only while the
     * feature is on (an online order paid from the wallet is confirmed even
     * after the feature was switched off, without re-enabling the method).
     */
    public function ensurePaymentMethod(): ?int
    {
        return $this->paymentMethodId() ?: $this->syncPaymentMethod($this->enabled(), true);
    }

    /** Is this payment method the customer-wallet tender? (feature state aside) */
    public function isWalletMethod($methodId): bool
    {
        $walletId = $this->paymentMethodId();

        return $walletId !== null && $methodId !== null && $methodId !== '' && (int) $methodId === $walletId;
    }

    /**
     * Keep the tender in step with the feature switch: create it on first
     * enable, (re)activate it while on, deactivate it while off so it leaves
     * the payment forms. Never a cash method (no change, no drawer).
     */
    public function syncPaymentMethod(bool $active, bool $createInactive = false): ?int
    {
        if (! Schema::hasColumn('settings', 'customer_wallet_payment_method_id')) {
            return null;
        }
        $this->forgetSettings();
        $id = $this->paymentMethodId();

        if (! $id) {
            if (! $active && ! $createInactive) {
                return null;
            }
            $attrs = ['name' => self::METHOD_NAME];
            if (Schema::hasColumn('payment_methods', 'is_active')) {
                $attrs['is_active'] = $active ? 1 : 0;
            }
            if (Schema::hasColumn('payment_methods', 'is_cash')) {
                $attrs['is_cash'] = 0;
            }
            $id = (int) DB::table('payment_methods')->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);
            Setting::query()->whereNull('deleted_at')->update(['customer_wallet_payment_method_id' => $id]);
            Setting::forgetCurrent();
        } elseif (Schema::hasColumn('payment_methods', 'is_active')) {
            DB::table('payment_methods')->where('id', $id)->update(['is_active' => $active ? 1 : 0]);
        }
        $this->forgetSettings();

        return $id;
    }

    /* ---------------------------------------------------------- lookups */

    public function findWallet(int $clientId): ?Wallet
    {
        return Wallet::clients()->where('owner_id', $clientId)->first();
    }

    /** Get (or lazily create) the wallet of a customer. */
    public function walletFor(int $clientId): Wallet
    {
        $keys = ['owner_type' => Wallet::OWNER_CLIENT, 'owner_id' => $clientId];
        try {
            return Wallet::firstOrCreate($keys, ['balance' => 0, 'status' => Wallet::STATUS_ACTIVE]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two first-time moves for the same customer raced on wallets_owner_unique.
            $wallet = Wallet::where($keys)->first();
            if ($wallet) {
                return $wallet;
            }
            throw $e;
        }
    }

    public function balanceFor(int $clientId): float
    {
        try {
            return (float) (Wallet::clients()->where('owner_id', $clientId)->value('balance') ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Balances keyed by client id (for list pages). */
    public function balancesFor(array $clientIds): array
    {
        if (! $clientIds || ! Schema::hasTable('wallets')) {
            return [];
        }

        return Wallet::clients()->whereIn('owner_id', $clientIds)
            ->pluck('balance', 'owner_id')
            ->map(fn ($b) => (float) $b)
            ->all();
    }

    /** What a payment form / the POS needs to offer the wallet tender. */
    public function summaryFor(?int $clientId): array
    {
        $enabled = $this->enabled();
        $wallet = ($enabled && $clientId) ? $this->findWallet($clientId) : null;

        return [
            'enabled' => $enabled,
            'balance' => $wallet ? round((float) $wallet->balance, 2) : 0.0,
            'status' => $wallet ? $wallet->status : Wallet::STATUS_ACTIVE,
            'allow_negative' => $enabled ? $this->allowsNegative() : false,
            'payment_method_id' => $enabled ? $this->paymentMethodId() : null,
        ];
    }

    /* ------------------------------------------------------- cash moves */

    /** Customer hands over cash to load the wallet. */
    public function topUp(Client $client, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($client, $amount, $opts) {
            $tx = $this->credit($this->walletFor($client->id), $amount, 'top_up', $opts);

            Account::adjustBalance($opts['account_id'] ?? null, $amount);
            $this->postJournal($tx, 'top_up');

            return $tx;
        });
    }

    /** Wallet balance paid back to the customer in cash. */
    public function refund(Client $client, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($client, $amount, $opts) {
            $tx = $this->debit($this->walletFor($client->id), $amount, 'refund', $opts, true);

            Account::adjustBalance($opts['account_id'] ?? null, -$amount);
            $this->postJournal($tx, 'refund');

            return $tx;
        });
    }

    /** Manual correction with no cash leg. $type is credit|debit. */
    public function adjust(Client $client, string $type, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);
        $opts['account_id'] = null;
        $opts['payment_method_id'] = null;

        return DB::transaction(function () use ($client, $type, $amount, $opts) {
            $wallet = $this->walletFor($client->id);
            $tx = $type === WalletTransaction::TYPE_DEBIT
                ? $this->debit($wallet, $amount, 'adjustment', $opts)
                : $this->credit($wallet, $amount, 'adjustment', $opts);

            $this->postJournal($tx, 'adjustment');

            return $tx;
        });
    }

    public function setStatus(Client $client, string $status): Wallet
    {
        $this->assertEnabled();
        if (! in_array($status, [Wallet::STATUS_ACTIVE, Wallet::STATUS_FROZEN], true)) {
            throw new WalletException('Unknown wallet status.', 'wallet_bad_status');
        }
        $wallet = $this->walletFor($client->id);
        $wallet->status = $status;
        $wallet->save();

        return $wallet;
    }

    /* --------------------------------------------------- document moves */

    /**
     * Spend wallet balance on a sale (or opening-balance) payment. The caller
     * stores the returned transaction id on its payment row.
     */
    public function payFromWallet(?int $clientId, float $amount, string $source = 'sale_payment', array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        if (! $clientId) {
            throw new WalletException('Choose a customer to pay from their wallet.', 'wallet_no_customer');
        }
        $amount = $this->normalizeAmount($amount);

        return $this->debit($this->walletFor($clientId), $amount, $source, $opts);
    }

    /** A sale-return payment settled as wallet credit instead of cash. */
    public function creditFromReturn(?int $clientId, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        if (! $clientId) {
            throw new WalletException('Choose a customer to credit their wallet.', 'wallet_no_customer');
        }
        $amount = $this->normalizeAmount($amount);

        return $this->credit($this->walletFor($clientId), $amount, 'return_credit', $opts);
    }

    /**
     * Opposite move of an earlier transaction (payment deleted / edited,
     * order cancelled). Never refuses on balance or status, and never undoes
     * the same transaction twice.
     */
    public function reverse(int $transactionId, array $opts = []): ?WalletTransaction
    {
        $tx = WalletTransaction::find($transactionId);
        if (! $tx) {
            return null;
        }
        return DB::transaction(function () use ($tx, $opts) {
            // Lock the wallet FIRST: every move on it queues behind this, so
            // two concurrent deletes of the same payment can't both see "not
            // reversed yet" and credit twice.
            $wallet = Wallet::withTrashed()->whereKey($tx->wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                return null;
            }
            $already = WalletTransaction::where('source', 'reversal')
                ->where('reference_type', WalletTransaction::class)
                ->where('reference_id', $tx->id)
                ->exists();
            if ($already) {
                return null;
            }
            $amount = (float) $tx->amount;
            // Money paid from the wallet for an online order may already have
            // been (partly) refunded to the wallet through an approved return
            // or a cancellation: give back only what is left.
            if ($tx->type === WalletTransaction::TYPE_DEBIT && ($order = $this->orderForTransaction($tx))) {
                $amount = min($amount, $this->walletPaidForOrder($order));
                if ($amount <= 0) {
                    return null;
                }
            }
            $type = $tx->type === WalletTransaction::TYPE_DEBIT ? WalletTransaction::TYPE_CREDIT : WalletTransaction::TYPE_DEBIT;

            return $this->apply($wallet, $type, $amount, 'reversal', [
                'reference_type' => WalletTransaction::class,
                'reference_id' => $tx->id,
                'note' => $opts['note'] ?? ('Reversal of #'.$tx->id),
                'user_id' => $opts['user_id'] ?? null,
                'date' => $opts['date'] ?? Carbon::now()->toDateString(),
            ], true);
        });
    }

    /**
     * Undo the wallet side of a deleted payment row (sale payment, sale
     * return payment, opening-balance payment). No-op for cash rows.
     */
    public function reversePayment($payment, string $label = 'Payment', array $opts = []): void
    {
        $txId = $payment->wallet_transaction_id ?? null;
        if (empty($txId)) {
            return;
        }
        $this->reverse((int) $txId, $opts + [
            'note' => $label.' '.($payment->Ref ?? ('#'.($payment->id ?? ''))).' removed',
            'user_id' => \Illuminate\Support\Facades\Auth::id(),
        ]);
    }

    /* ------------------------------------------------------- gift cards */

    /**
     * Redeem a gift card / voucher code into a customer's wallet. The whole
     * remaining value is credited and the card is closed.
     */
    public function redeemGiftCard(string $code, int $clientId, ?int $userId = null): array
    {
        $this->assertEnabled();
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new WalletException('Enter a gift card code.', 'gift_card_code_required');
        }

        return DB::transaction(function () use ($code, $clientId, $userId) {
            $card = GiftCard::where('code', $code)->lockForUpdate()->first();
            if (! $card) {
                throw new WalletException('This gift card code is not valid.', 'gift_card_invalid');
            }

            // Flip a lapsed card so its status stays truthful.
            if ($card->status === 'active' && $card->expires_at !== null && $card->expires_at->isPast()) {
                $card->status = 'expired';
                $card->save();
            }
            if (! $card->isRedeemable()) {
                throw new WalletException('This gift card has already been used, has expired or is disabled.', 'gift_card_not_redeemable');
            }

            $value = round((float) $card->balance, 2);
            $wallet = $this->walletFor($clientId);
            $tx = $this->credit($wallet, $value, 'gift_card', [
                'reference_type' => GiftCard::class,
                'reference_id' => $card->id,
                'note' => 'Gift card '.$card->code,
                'user_id' => $userId,
            ]);
            $this->postJournal($tx, 'gift_card');

            $card->balance = 0;
            $card->status = 'redeemed';
            $card->redeemed_by = $clientId;
            $card->redeemed_at = Carbon::now();
            if (Schema::hasColumn('gift_cards', 'wallet_transaction_id')) {
                $card->wallet_transaction_id = $tx->id;
            }
            $card->save();

            return ['wallet' => $wallet->fresh(), 'transaction' => $tx, 'gift_card' => $card];
        });
    }

    /** Unique, readable code such as GC-3F9K-8QLZ. */
    public function generateGiftCardCode(string $prefix = 'GC'): string
    {
        $prefix = mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'GC'), 0, 12);
        // No look-alike characters (0/O, 1/I/L) in the random part of printed codes.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $chunk = function () use ($alphabet) {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $out;
        };
        do {
            $code = $prefix.'-'.$chunk().'-'.$chunk();
        } while (GiftCard::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /* ------------------------------------------------------ withdrawals */

    /** A storefront customer asks to cash out part of the balance. */
    public function requestWithdrawal(int $clientId, float $amount, ?string $method, ?string $destination): WalletWithdrawal
    {
        if (! $this->withdrawalsEnabled()) {
            throw new WalletException('Withdrawals are not available.', 'withdrawals_disabled');
        }
        $amount = $this->normalizeAmount($amount);
        $min = $this->minWithdrawal();
        if ($min > 0 && $amount < $min) {
            throw new WalletException('The minimum withdrawal is '.$min.'.', 'withdrawal_below_minimum', ['min' => $min]);
        }

        return DB::transaction(function () use ($clientId, $amount, $method, $destination) {
            $wallet = $this->walletFor($clientId);
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            if (! $locked->isActive()) {
                throw new WalletException('This wallet is frozen.', 'wallet_frozen');
            }
            // Open requests already spoken for count against the balance.
            $open = (float) WalletWithdrawal::where('wallet_id', $locked->id)->where('status', 'pending')->sum('amount');
            if ($amount + $open > (float) $locked->balance + 0.00001) {
                throw new WalletException('The amount is more than the balance available to withdraw.', 'withdrawal_exceeds_available', [
                    'balance' => (float) $locked->balance,
                    'pending' => $open,
                ]);
            }

            return WalletWithdrawal::create([
                'wallet_id' => $locked->id,
                'amount' => $amount,
                'status' => 'pending',
                'method' => $method ? mb_substr($method, 0, 60) : null,
                'destination' => $destination ? mb_substr($destination, 0, 190) : null,
            ]);
        });
    }

    /** Approve a pending request: the wallet is debited now. */
    public function approveWithdrawal(int $id, ?int $userId, ?string $note): WalletWithdrawal
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($id, $userId, $note) {
            $w = WalletWithdrawal::lockForUpdate()->findOrFail($id);
            if ($w->status !== 'pending') {
                throw new WalletException('Only a pending request can be approved.', 'withdrawal_not_pending');
            }
            $wallet = Wallet::find($w->wallet_id);
            if (! $wallet) {
                throw new WalletException('This wallet no longer exists.', 'wallet_missing');
            }
            // Withdrawals never overdraw a wallet, whatever allow_negative says.
            $tx = $this->apply($wallet, WalletTransaction::TYPE_DEBIT, (float) $w->amount, 'withdrawal', [
                'reference_type' => WalletWithdrawal::class,
                'reference_id' => $w->id,
                'note' => 'Withdrawal #'.$w->id.' approved',
                'user_id' => $userId,
            ], false, true);

            $w->update([
                'status' => 'approved',
                'note' => $note !== null ? mb_substr($note, 0, 255) : $w->note,
                'wallet_transaction_id' => $tx->id,
                'processed_by' => $userId,
                'processed_at' => Carbon::now(),
            ]);

            return $w->fresh();
        });
    }

    /**
     * Reject a request. A pending one changes nothing; an approved one (not
     * yet paid) has its wallet debit given back.
     */
    public function rejectWithdrawal(int $id, ?int $userId, ?string $note): WalletWithdrawal
    {
        return DB::transaction(function () use ($id, $userId, $note) {
            $w = WalletWithdrawal::lockForUpdate()->findOrFail($id);
            if (! in_array($w->status, ['pending', 'approved'], true)) {
                throw new WalletException('This request can no longer be rejected.', 'withdrawal_not_open');
            }
            if ($w->status === 'approved' && $w->wallet_transaction_id) {
                $this->reverse((int) $w->wallet_transaction_id, [
                    'note' => 'Withdrawal #'.$w->id.' rejected', 'user_id' => $userId,
                ]);
            }
            $w->update([
                'status' => 'rejected',
                'note' => $note !== null ? mb_substr($note, 0, 255) : $w->note,
                'processed_by' => $userId,
                'processed_at' => Carbon::now(),
            ]);

            return $w->fresh();
        });
    }

    /** The approved amount has been paid out of a cash/bank account. */
    public function markWithdrawalPaid(int $id, ?int $userId, ?int $accountId, ?int $paymentMethodId, ?string $note): WalletWithdrawal
    {
        return DB::transaction(function () use ($id, $userId, $accountId, $paymentMethodId, $note) {
            $w = WalletWithdrawal::lockForUpdate()->findOrFail($id);
            if ($w->status !== 'approved') {
                throw new WalletException('Only an approved request can be marked paid.', 'withdrawal_not_approved');
            }
            $w->update([
                'status' => 'paid',
                'account_id' => $accountId,
                'payment_method_id' => $paymentMethodId,
                'note' => $note !== null ? mb_substr($note, 0, 255) : $w->note,
                'processed_by' => $userId,
                'paid_at' => Carbon::now(),
            ]);

            Account::adjustBalance($accountId, -(float) $w->amount);
            if ($w->wallet_transaction_id && ($tx = WalletTransaction::find($w->wallet_transaction_id))) {
                // The cash leg of the payout settles the liability.
                $this->postJournal($tx, 'withdrawal', $accountId, Carbon::now()->toDateString());
            }

            return $w->fresh();
        });
    }

    /* ------------------------------------------------------- storefront */

    /**
     * Pay a storefront order from the wallet. Never overdraws, whatever the
     * back-office allow_negative switch says.
     */
    public function chargeOrder(OnlineOrder $order, int $clientId, float $amount): WalletTransaction
    {
        if (! $this->checkoutEnabled()) {
            throw new WalletException('Paying with the wallet is not available.', 'wallet_disabled');
        }
        $amount = $this->normalizeAmount($amount);

        return $this->apply($this->walletFor($clientId), WalletTransaction::TYPE_DEBIT, $amount, 'checkout', [
            'reference_type' => OnlineOrder::class,
            'reference_id' => $order->id,
            'note' => 'Online order '.$order->ref,
        ], false, true);
    }

    /** The wallet charge an order was paid with, if any (not yet reversed). */
    public function checkoutTransactionFor(OnlineOrder $order): ?WalletTransaction
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return null;
        }

        return WalletTransaction::where('source', 'checkout')
            ->where('reference_type', OnlineOrder::class)
            ->where('reference_id', $order->id)
            ->orderByDesc('id')
            ->first();
    }

    /** Should a refund of this order go to the customer's wallet? */
    public function shouldRefundToWallet(OnlineOrder $order): bool
    {
        if (! $order->client_id) {
            return false;
        }
        // Paid from the wallet: the money can only go back there.
        if ($order->payment_method === 'wallet') {
            return Schema::hasTable('wallets');
        }

        // Other orders only when money was actually received for them; an
        // unpaid (e.g. COD not yet collected) order keeps the old manual
        // refund path instead of minting wallet credit.
        return $this->enabled() && $this->settings()['refund_destination'] === 'wallet'
            && $this->paidForOrder($order) > 0;
    }

    /** What the customer actually paid for the order (any tender). */
    protected function paidForOrder(OnlineOrder $order): float
    {
        if ($order->payment_method === 'wallet') {
            return $this->walletPaidForOrder($order);
        }
        $sale = $order->sale_id ? DB::table('sales')->whereNull('deleted_at')->where('id', $order->sale_id)->first(['paid_amount']) : null;
        if ($sale) {
            return round((float) $sale->paid_amount, 2);
        }

        return ($order->payment_status ?? '') === 'paid' ? round((float) $order->total, 2) : 0.0;
    }

    /**
     * Net money that left the wallet for an order: its checkout charge plus
     * any wallet payment on the Sale made from it, minus what was reversed
     * and what was already refunded to the wallet.
     */
    public function walletPaidForOrder(OnlineOrder $order): float
    {
        $debits = WalletTransaction::where('type', WalletTransaction::TYPE_DEBIT)
            ->where(function ($q) use ($order) {
                $q->where(fn ($w) => $w->where('source', 'checkout')->where('reference_type', OnlineOrder::class)->where('reference_id', $order->id));
                if ($order->sale_id) {
                    $q->orWhere(fn ($w) => $w->where('source', 'sale_payment')->where('reference_type', \App\Models\Sale::class)->where('reference_id', $order->sale_id));
                }
            })
            ->get(['id', 'amount']);
        if ($debits->isEmpty()) {
            return 0.0;
        }
        $reversed = (float) WalletTransaction::where('source', 'reversal')
            ->where('reference_type', WalletTransaction::class)->whereIn('reference_id', $debits->pluck('id'))->sum('amount');
        $refunded = (float) WalletTransaction::where('source', 'order_refund')
            ->where('reference_type', OnlineOrder::class)->where('reference_id', $order->id)->sum('amount');

        return max(0.0, round((float) $debits->sum('amount') - $reversed - $refunded, 2));
    }

    /** The online order a wallet debit paid for (checkout charge or a payment on its Sale). */
    protected function orderForTransaction(WalletTransaction $tx): ?OnlineOrder
    {
        if ($tx->source === 'checkout' && $tx->reference_type === OnlineOrder::class) {
            return OnlineOrder::find($tx->reference_id);
        }
        if ($tx->source === 'sale_payment' && $tx->reference_type === \App\Models\Sale::class && Schema::hasColumn('online_orders', 'sale_id')) {
            return OnlineOrder::where('sale_id', $tx->reference_id)->first();
        }

        return null;
    }

    /**
     * Credit an online-order refund (approved return / cancellation, or an
     * admin cancelling a wallet-paid order) to the customer's wallet.
     *
     * For an order paid FROM the wallet the credits are capped at what was
     * charged, so the two cancellation paths (admin status change and an
     * approved customer request) can never refund the same money twice.
     * Returns null when nothing is left to refund.
     */
    public function refundOrder(OnlineOrder $order, float $amount, ?int $userId = null): ?WalletTransaction
    {
        if (! $order->client_id || ! Schema::hasTable('wallets')) {
            return null;
        }
        $wallet = $this->walletFor((int) $order->client_id);

        return DB::transaction(function () use ($order, $amount, $userId, $wallet) {
            // Lock first, then cap: two refund paths racing for the same
            // order can't both see the full amount as still refundable.
            Wallet::withTrashed()->whereKey($wallet->id)->lockForUpdate()->first();

            $order = OnlineOrder::find($order->id) ?? $order;
            $refunded = (float) WalletTransaction::where('source', 'order_refund')
                ->where('reference_type', OnlineOrder::class)->where('reference_id', $order->id)->sum('amount');
            $left = $order->payment_method === 'wallet'
                ? $this->walletPaidForOrder($order)                       // already net of refunds
                : round($this->paidForOrder($order) - $refunded, 2);
            $amount = min(round($amount, 2), $left);
            if ($amount <= 0) {
                return null;
            }

            // Forced: a refund owed to the customer is never blocked by a
            // frozen wallet or the feature being switched off since they paid.
            return $this->apply($wallet, WalletTransaction::TYPE_CREDIT, $amount, 'order_refund', [
                'reference_type' => OnlineOrder::class,
                'reference_id' => $order->id,
                'note' => 'Refund for online order '.$order->ref,
                'user_id' => $userId,
            ], true);
        });
    }

    /* ------------------------------------------------------ core ledger */

    public function credit(Wallet $wallet, float $amount, string $source, array $opts = []): WalletTransaction
    {
        return $this->apply($wallet, WalletTransaction::TYPE_CREDIT, $amount, $source, $opts);
    }

    /** $strict refuses to overdraw even when negative balances are allowed. */
    public function debit(Wallet $wallet, float $amount, string $source, array $opts = [], bool $strict = false): WalletTransaction
    {
        return $this->apply($wallet, WalletTransaction::TYPE_DEBIT, $amount, $source, $opts, false, $strict);
    }

    /**
     * Core mutation under a row lock. Joins an outer transaction. $force
     * skips the frozen / insufficient checks (reversals, refunds owed);
     * $strict ignores allow_negative (withdrawals, storefront, cash refunds).
     */
    protected function apply(Wallet $wallet, string $type, float $amount, string $source, array $opts, bool $force = false, bool $strict = false): WalletTransaction
    {
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($wallet, $type, $amount, $source, $opts, $force, $strict) {
            $locked = Wallet::withTrashed()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if (! $force && ! $locked->isActive()) {
                throw new WalletException('This customer wallet is frozen.', 'wallet_frozen');
            }

            $current = (float) $locked->balance;
            if ($type === WalletTransaction::TYPE_DEBIT) {
                $new = round($current - $amount, 2);
                if (! $force && $new < 0 && ($strict || ! $this->allowsNegative())) {
                    throw new WalletException('Insufficient wallet balance.', 'wallet_insufficient_balance', [
                        'balance' => $current,
                        'required' => $amount,
                    ]);
                }
            } else {
                $new = round($current + $amount, 2);
            }

            $locked->balance = $new;
            $locked->save();

            $tx = WalletTransaction::create([
                'wallet_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $new,
                'source' => in_array($source, WalletTransaction::CLIENT_SOURCES, true) ? $source : 'adjustment',
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'account_id' => $opts['account_id'] ?? null,
                'payment_method_id' => $opts['payment_method_id'] ?? null,
                'date' => $this->dateOf($opts['date'] ?? null),
                'note' => isset($opts['note']) ? mb_substr((string) $opts['note'], 0, 500) : null,
                'user_id' => $opts['user_id'] ?? null,
            ]);

            $wallet->balance = $new;
            $wallet->status = $locked->status;

            return $tx;
        });
    }

    /* -------------------------------------------------------- journals */

    /**
     * Journal for a wallet move that is not a document payment. The wallet
     * is a liability: money in raises it (Cr 2310), money out lowers it
     * (Dr 2310). Cash moves offset a cash account; adjustments and gift
     * cards offset retained earnings, like the supplier-wallet adjustment.
     */
    protected function postJournal(WalletTransaction $tx, string $kind, ?int $accountId = null, ?string $date = null): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }
        try {
            $walletCoa = AccountingHelper::getOrCreateCoa(self::COA_CODE, self::COA_NAME, 'liability');
            if (in_array($kind, ['adjustment', 'gift_card'], true)) {
                $otherId = AccountingHelper::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            } else {
                $otherId = AccountingHelper::getOrCreateCoaForLegacyAccount($accountId ?? $tx->account_id)
                    ?: AccountingHelper::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            if (! $walletCoa || ! $otherId) {
                return;
            }
            $amount = (float) $tx->amount;
            $walletUp = $tx->type === WalletTransaction::TYPE_CREDIT;
            $memo = match ($kind) {
                'adjustment' => 'Wallet adjustment',
                'gift_card' => 'Gift card redeemed',
                default => $walletUp ? 'Cash In' : 'Cash Out',
            };
            AccountingHelper::createBalancedEntry([
                'journal' => [
                    'date' => $date ?: ($tx->date ?: Carbon::now()->toDateString()),
                    'description' => 'Customer wallet '.str_replace('_', ' ', $kind).' #'.$tx->id,
                    'reference_type' => 'customer_wallet_transaction',
                    'reference_id' => $tx->id,
                ],
                'lines' => [
                    ['coa_id' => $otherId,   'debit' => $walletUp ? $amount : 0, 'credit' => $walletUp ? 0 : $amount, 'memo' => $memo],
                    ['coa_id' => $walletCoa, 'debit' => $walletUp ? 0 : $amount, 'credit' => $walletUp ? $amount : 0, 'memo' => 'Customer wallet'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CustomerWallet] journal failed: '.$e->getMessage());
        }
    }

    /* ----------------------------------------------------------- utils */

    /** ['wallet_transaction_id' => id] for a create() array, or [] (keeps un-migrated tenants working). */
    public static function txColumn($txId): array
    {
        return $txId ? ['wallet_transaction_id' => (int) $txId] : [];
    }

    protected function normalizeAmount(float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new WalletException('Amount must be greater than zero.', 'wallet_invalid_amount');
        }

        return $amount;
    }

    protected function dateOf($date): string
    {
        try {
            return $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();
        } catch (\Throwable $e) {
            return Carbon::now()->toDateString();
        }
    }
}
