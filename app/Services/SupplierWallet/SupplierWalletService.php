<?php

namespace App\Services\SupplierWallet;

use App\Models\Account;
use App\Models\PaymentPurchase;
use App\Models\PaymentPurchaseReturns;
use App\Models\Provider;
use App\Models\ProviderPaymentOpeningBalance;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use App\Services\AccountingV2\AccountingHelper;
use App\Services\TenantLimitsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Server-authoritative supplier wallet ledger.
 *
 * A wallet is money the business holds WITH a supplier: advances paid ahead
 * of purchases (top-ups), credit notes the supplier owes back (purchase
 * return payments credited to the wallet) and manual corrections. It is
 * spent by paying purchases from it. All balance mutations go through
 * credit()/debit() so every change writes a wallet_transactions row and the
 * running balance stays consistent under concurrency (row locks).
 *
 * Accounting (Accounting V2, when journals are automated):
 *   top-up            Dr 1300-SUPADV / Cr cash account
 *   refund            Dr cash account / Cr 1300-SUPADV
 *   adjustment        Dr|Cr 1300-SUPADV against retained earnings
 *   purchase payment  posted by the payment row itself (AccountingHelper
 *                     ::fromPaymentPurchase swaps cash for 1300-SUPADV when
 *                     the row carries wallet_transaction_id)
 *   return credit     same, via fromPaymentPurchaseReturn
 */
class SupplierWalletService
{
    use SkipsWhenManual;

    public const COA_CODE = '1300-SUPADV';

    public const COA_NAME = 'Supplier Advances';

    public const FEATURE = 'supplier_wallet';

    protected ?bool $enabledMemo = null;

    protected ?bool $allowNegativeMemo = null;

    /** Both tenant switches in one read (null when the columns are not migrated yet). */
    protected function switches(): array
    {
        try {
            if (! Schema::hasColumn('settings', 'supplier_wallet_enabled')) {
                return ['enabled' => false, 'allow_negative' => false];
            }
            $row = Setting::query()->whereNull('deleted_at')->first(['supplier_wallet_enabled', 'supplier_wallet_allow_negative']);

            return [
                'enabled' => (bool) ($row->supplier_wallet_enabled ?? false),
                'allow_negative' => (bool) ($row->supplier_wallet_allow_negative ?? false),
            ];
        } catch (\Throwable $e) {
            return ['enabled' => false, 'allow_negative' => false];
        }
    }

    /**
     * Did a payment request ask for the wallet tender? Purchase payments send
     * from_wallet, purchase-return payments send to_wallet.
     */
    public static function wantsWallet($request): bool
    {
        foreach (['from_wallet', 'to_wallet'] as $key) {
            if (in_array($request->input($key), [1, '1', true, 'true'], true)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------ gates */

    /** Settings switch AND plan feature; both must be on. */
    public function enabled(): bool
    {
        if ($this->enabledMemo !== null) {
            return $this->enabledMemo;
        }
        try {
            $switches = $this->switches();
            $this->allowNegativeMemo = $switches['allow_negative'];
            $on = $switches['enabled'] && Schema::hasTable('wallets');
            if ($on) {
                $on = app(TenantLimitsService::class)->hasFeature(self::FEATURE);
            }
        } catch (\Throwable $e) {
            $on = false;
        }

        return $this->enabledMemo = $on;
    }

    public function allowsNegative(): bool
    {
        if ($this->allowNegativeMemo === null) {
            $this->allowNegativeMemo = $this->switches()['allow_negative'];
        }

        return $this->allowNegativeMemo;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new WalletException('Supplier wallet is not enabled.', 'wallet_disabled');
        }
    }

    /* ---------------------------------------------------------- lookups */

    public function findWallet(int $providerId): ?Wallet
    {
        return Wallet::providers()->where('owner_id', $providerId)->first();
    }

    /** Get (or lazily create) the wallet of a supplier. */
    public function walletFor(int $providerId): Wallet
    {
        $keys = ['owner_type' => Wallet::OWNER_PROVIDER, 'owner_id' => $providerId];
        try {
            return Wallet::firstOrCreate($keys, ['balance' => 0, 'status' => Wallet::STATUS_ACTIVE]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two first-time moves for the same supplier raced on
            // wallets_owner_unique: the other request's row now exists.
            $wallet = Wallet::where($keys)->first();
            if ($wallet) {
                return $wallet;
            }
            throw $e;
        }
    }

    public function balanceFor(int $providerId): float
    {
        return (float) (Wallet::providers()->where('owner_id', $providerId)->value('balance') ?? 0);
    }

    /** Balances keyed by provider id (for list pages). */
    public function balancesFor(array $providerIds): array
    {
        if (! $providerIds || ! Schema::hasTable('wallets')) {
            return [];
        }

        return Wallet::providers()->whereIn('owner_id', $providerIds)
            ->pluck('balance', 'owner_id')
            ->map(fn ($b) => (float) $b)
            ->all();
    }

    /** What a payment form needs to offer "pay from wallet". */
    public function summaryFor(int $providerId): array
    {
        $enabled = $this->enabled();
        $wallet = $enabled ? $this->findWallet($providerId) : null;

        return [
            'enabled' => $enabled,
            'balance' => $wallet ? (float) $wallet->balance : 0.0,
            'status' => $wallet ? $wallet->status : Wallet::STATUS_ACTIVE,
            'allow_negative' => $enabled ? $this->allowsNegative() : false,
        ];
    }

    /* ------------------------------------------------------- cash moves */

    /**
     * Money paid to the supplier in advance. Leaves the cash account and
     * lands in the wallet.
     */
    public function topUp(Provider $provider, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($provider, $amount, $opts) {
            $tx = $this->credit($this->walletFor($provider->id), $amount, 'top_up', $opts);

            Account::adjustBalance($opts['account_id'] ?? null, -$amount);
            $this->postCashJournal($tx, 'top_up');

            return $tx;
        });
    }

    /**
     * The supplier gives an advance back in cash. Leaves the wallet and lands
     * in the cash account.
     */
    public function refund(Provider $provider, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($provider, $amount, $opts) {
            $tx = $this->debit($this->walletFor($provider->id), $amount, 'refund', $opts);

            Account::adjustBalance($opts['account_id'] ?? null, $amount);
            $this->postCashJournal($tx, 'refund');

            return $tx;
        });
    }

    /** Manual correction with no cash leg. $type is credit|debit. */
    public function adjust(Provider $provider, string $type, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);
        $opts['account_id'] = null;
        $opts['payment_method_id'] = null;

        return DB::transaction(function () use ($provider, $type, $amount, $opts) {
            $wallet = $this->walletFor($provider->id);
            $tx = $type === WalletTransaction::TYPE_DEBIT
                ? $this->debit($wallet, $amount, 'adjustment', $opts)
                : $this->credit($wallet, $amount, 'adjustment', $opts);

            $this->postCashJournal($tx, 'adjustment');

            return $tx;
        });
    }

    public function setStatus(Provider $provider, string $status): Wallet
    {
        $this->assertEnabled();
        if (! in_array($status, [Wallet::STATUS_ACTIVE, Wallet::STATUS_FROZEN], true)) {
            throw new WalletException('Unknown wallet status.', 'wallet_bad_status');
        }
        $wallet = $this->walletFor($provider->id);
        $wallet->status = $status;
        $wallet->save();

        return $wallet;
    }

    /* --------------------------------------------------- document moves */

    /**
     * Spend wallet balance on a purchase (or opening-balance) payment. The
     * caller stores the returned transaction id on its payment row.
     */
    public function payFromWallet(int $providerId, float $amount, string $source, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return $this->debit($this->walletFor($providerId), $amount, $source, $opts);
    }

    /** A purchase-return payment settled as wallet credit instead of cash. */
    public function creditFromReturn(int $providerId, float $amount, array $opts = []): WalletTransaction
    {
        $this->assertEnabled();
        $amount = $this->normalizeAmount($amount);

        return $this->credit($this->walletFor($providerId), $amount, 'return_credit', $opts);
    }

    /**
     * Opposite move of an earlier transaction (payment deleted / edited).
     * Never refuses on balance or status: undoing must always succeed.
     */
    public function reverse(int $transactionId, array $opts = []): ?WalletTransaction
    {
        $tx = WalletTransaction::find($transactionId);
        if (! $tx) {
            return null;
        }
        $wallet = Wallet::withTrashed()->find($tx->wallet_id);
        if (! $wallet) {
            return null;
        }
        $type = $tx->type === WalletTransaction::TYPE_DEBIT ? WalletTransaction::TYPE_CREDIT : WalletTransaction::TYPE_DEBIT;

        return $this->apply($wallet, $type, (float) $tx->amount, 'reversal', [
            'reference_type' => WalletTransaction::class,
            'reference_id' => $tx->id,
            'note' => $opts['note'] ?? ('Reversal of #'.$tx->id),
            'user_id' => $opts['user_id'] ?? null,
            'date' => $opts['date'] ?? Carbon::now()->toDateString(),
        ], true);
    }

    /**
     * Give a deleted wallet-funded purchase payment back to the wallet. The
     * accounting reversal is NOT posted here: the caller fires
     * PaymentPurchaseDeleted for every deleted payment row (cash or wallet),
     * and the Accounting V2 listener picks the right settlement account.
     */
    public function reversePaymentPurchase(PaymentPurchase $payment, array $opts = []): void
    {
        if (empty($payment->wallet_transaction_id)) {
            return;
        }
        $this->reverse((int) $payment->wallet_transaction_id, $opts + ['note' => 'Purchase payment '.$payment->Ref.' removed']);
    }

    /**
     * Take a deleted wallet-credited return payment back out of the wallet.
     * No journal: purchase-return payments post none today for any tender
     * (PaymentPurchaseReturnCreated/Deleted have no listeners), so a reversal
     * here would have nothing to offset.
     */
    public function reversePaymentPurchaseReturn(PaymentPurchaseReturns $payment, array $opts = []): void
    {
        if (empty($payment->wallet_transaction_id)) {
            return;
        }
        $this->reverse((int) $payment->wallet_transaction_id, $opts + ['note' => 'Return payment '.$payment->Ref.' removed']);
    }

    public function reverseOpeningBalancePayment(ProviderPaymentOpeningBalance $payment, array $opts = []): void
    {
        if (empty($payment->wallet_transaction_id)) {
            return;
        }
        $this->reverse((int) $payment->wallet_transaction_id, $opts + ['note' => 'Opening balance payment '.$payment->Ref.' removed']);
    }

    /* ------------------------------------------------------ core ledger */

    public function credit(Wallet $wallet, float $amount, string $source, array $opts = []): WalletTransaction
    {
        return $this->apply($wallet, WalletTransaction::TYPE_CREDIT, $amount, $source, $opts);
    }

    public function debit(Wallet $wallet, float $amount, string $source, array $opts = []): WalletTransaction
    {
        return $this->apply($wallet, WalletTransaction::TYPE_DEBIT, $amount, $source, $opts);
    }

    /**
     * Core mutation under a row lock. Safe inside an outer transaction (it
     * joins it). $force skips the frozen / insufficient checks (reversals).
     */
    protected function apply(Wallet $wallet, string $type, float $amount, string $source, array $opts, bool $force = false): WalletTransaction
    {
        $amount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($wallet, $type, $amount, $source, $opts, $force) {
            $locked = Wallet::withTrashed()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if (! $force && ! $locked->isActive()) {
                throw new WalletException('This supplier wallet is frozen.', 'wallet_frozen');
            }

            $current = (float) $locked->balance;
            if ($type === WalletTransaction::TYPE_DEBIT) {
                $new = round($current - $amount, 2);
                if (! $force && $new < 0 && ! $this->allowsNegative()) {
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
                'source' => in_array($source, WalletTransaction::SOURCES, true) ? $source : 'adjustment',
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'account_id' => $opts['account_id'] ?? null,
                'payment_method_id' => $opts['payment_method_id'] ?? null,
                'date' => $this->dateOf($opts['date'] ?? null),
                'note' => isset($opts['note']) ? mb_substr((string) $opts['note'], 0, 500) : null,
                'user_id' => $opts['user_id'] ?? null,
            ]);

            // Keep the caller's in-memory model in step.
            $wallet->balance = $new;
            $wallet->status = $locked->status;

            return $tx;
        });
    }

    /* -------------------------------------------------------- journals */

    /**
     * Journal for a cash-backed wallet move. Top-up: the advance is an asset
     * we now hold with the supplier (Dr 1300) paid out of cash (Cr cash).
     * Refund is the mirror. Adjustments have no cash leg and book against
     * retained earnings, like the legacy deposit journal does. Gated by the
     * same automation switch as the Accounting V2 listeners (SkipsWhenManual).
     */
    protected function postCashJournal(WalletTransaction $tx, string $kind): void
    {
        if ($this->shouldSkipAutomation()) {
            return;
        }
        try {
            $advId = AccountingHelper::getOrCreateCoa(self::COA_CODE, self::COA_NAME, 'asset');
            if ($kind === 'adjustment') {
                $otherId = AccountingHelper::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            } else {
                $otherId = AccountingHelper::getOrCreateCoaForLegacyAccount($tx->account_id)
                    ?: AccountingHelper::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            if (! $advId || ! $otherId) {
                return;
            }
            $amount = (float) $tx->amount;
            $walletUp = $tx->type === WalletTransaction::TYPE_CREDIT;
            AccountingHelper::createBalancedEntry([
                'journal' => [
                    'date' => $tx->date ?: Carbon::now()->toDateString(),
                    'description' => 'Supplier wallet '.str_replace('_', ' ', $kind).' #'.$tx->id,
                    'reference_type' => 'wallet_transaction',
                    'reference_id' => $tx->id,
                ],
                'lines' => [
                    ['coa_id' => $advId,   'debit' => $walletUp ? $amount : 0, 'credit' => $walletUp ? 0 : $amount, 'memo' => 'Supplier advance'],
                    ['coa_id' => $otherId, 'debit' => $walletUp ? 0 : $amount, 'credit' => $walletUp ? $amount : 0, 'memo' => $kind === 'adjustment' ? 'Wallet adjustment' : ($walletUp ? 'Cash Out' : 'Cash In')],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SupplierWallet] journal failed: '.$e->getMessage());
        }
    }

    /* ----------------------------------------------------------- utils */

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
