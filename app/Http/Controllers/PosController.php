<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\DraftSale;
use App\Models\DraftSaleDetail;
use App\Models\EmailMessage;
use App\Models\Language;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\PaymentWithCreditCard;
use App\Models\PosOfflineSale;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\PromotionUsage;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalesAgent;
use App\Models\Setting;
use App\Models\SMSMessage;
use App\Models\Unit;
use App\Http\Controllers\Concerns\ChecksSaleStock;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\Pos\OfflineSaleRecorder;
use App\Services\PosTerminals\TerminalSaleLinker;
use App\Services\PromotionEngine;
use App\Services\ComboStockService;
use App\Services\SerialNumberService;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\WarehousePrices;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use Stripe;
use Twilio\Rest\Client as Client_Twilio;

class PosController extends BaseController
{
    use ChecksSaleStock;

    // ------------ Create New  POS --------------\\

    /**
     * Back-compat: collapse a detail row's serial_numbers (array or delimited
     * string) into the legacy comma-separated imei_number text column so existing
     * receipt templates keep working. Falls back to the raw imei_number.
     */
    private function resolveImeiString($value)
    {
        $serials = $value['serial_numbers'] ?? null;
        if (is_string($serials)) {
            $serials = preg_split('/[\r\n,;\t]+/', $serials) ?: [];
        }
        if (is_array($serials)) {
            $clean = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $serials), fn ($s) => $s !== ''));
            if (! empty($clean)) {
                return implode(',', $clean);
            }
        }

        return $value['imei_number'] ?? null;
    }

    /**
     * Authorise every card line with Stripe before the sale transaction opens.
     *
     * Charging inside the transaction meant a real card charge could outlive a
     * rollback: the bank debited the customer and no sale existed in Stocky. The
     * funds are now only held here (capture_method: manual) and taken once the
     * sale has committed; if it fails, the hold is released instead. Keeping the
     * round trip outside the transaction also stops Stripe's network latency
     * holding database row locks open for seconds at a time.
     *
     * @param  array|null  $payments  payment lines to authorise; defaults to the request's `payments`
     *                                (submit_sale_from_draft passes its single `payment` line here)
     * @return array<int, array{intent_id: string, charge_id: ?string, customer_stripe_id: string}>
     *                                                          keyed by payment line index
     */
    private function authoriseCardPayments(Request $request, $changeLineIndex, $overpayment, ?array $payments = null): array
    {
        $authorisations = [];

        // One idempotency base per request: a network-level retry of any Stripe
        // create call below returns the original object instead of placing a
        // second hold on the card. Never derived from sale_uuid — a resubmit of
        // a sale whose earlier hold was released must get a fresh authorisation.
        $stripeIdemBase = ($payments === null ? 'pos-sale:' : 'pos-draft-sale:').Str::uuid();

        // Optional Stripe per-line metadata coming from modern payment modal / legacy POS
        // NOTE: Saved-card usage has been disabled; we only accept per-transaction tokens.
        $cardTokensByLine = $request->card_tokens_by_line ?? [];

        foreach ($payments ?? $request->payments as $index => $payment) {
            if (! isset($payment['amount']) || $payment['amount'] <= 0) {
                continue;
            }

            if ($payment['payment_method_id'] != 1 && $payment['payment_method_id'] != '1') {
                continue;
            }

            // Already charged on a POS device (Card terminal line).
            if (TerminalSaleLinker::isTerminalLine((array) $payment)) {
                continue;
            }

            // Hold what will actually be recorded as paid, not what was tendered.
            $amount = (float) $payment['amount'];
            if ($changeLineIndex !== null && $index === $changeLineIndex) {
                $amount -= (float) $overpayment;
            }

            $helpers = new helpers();
            $currency = strtolower($helpers->Get_Currency_Code() ?? 'usd');

            $client = Client::findOrFail($request->client_id);
            \Stripe\Stripe::setApiKey(\App\Models\PaymentSetting::current()->stripe_secret);

            $existing = PaymentWithCreditCard::where('customer_id', $request->client_id)->first();

            try {

                // 1️⃣ Ensure Stripe customer (no legacy card "source" – we rely on PaymentMethods / PaymentIntents)
                if (! $existing) {
                    $customer = \Stripe\Customer::create([
                        'email' => $client->email,
                        'name'  => $client->name,
                    ], ['idempotency_key' => $stripeIdemBase.':customer']);

                    $customerStripeId = $customer->id;
                } else {
                    $customerStripeId = $existing->customer_stripe_id;
                }

                // 2️⃣ Resolve Stripe Payment Method ID (pm_...)
                // Prefer explicit PaymentMethod id sent from frontend (new POS flow)
                $paymentMethodId = $payment['payment_method_id_stripe'] ?? null;

                // If not provided, but we have a card token (legacy token-based flow),
                // create a PaymentMethod from the token so we can still use PaymentIntents.
                if (! $paymentMethodId) {
                    $tokenLine = $cardTokensByLine[$index] ?? ($request->token ?? null);
                    if ($tokenLine) {
                        $paymentMethod = \Stripe\PaymentMethod::create([
                            'type' => 'card',
                            'card' => [
                                'token' => $tokenLine,
                            ],
                        ], ['idempotency_key' => $stripeIdemBase.':pm:'.$index]);
                        $paymentMethodId = $paymentMethod->id;
                    }
                }

                if (! $paymentMethodId) {
                    throw new \Exception('Stripe payment method is missing');
                }

                // 3️⃣ Create & confirm PaymentIntent — authorisation only, the
                //     capture happens after the sale has committed.
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => (int) round($amount * 100),
                    'currency' => $currency, // usd or mxn
                    'customer' => $customerStripeId,
                    'payment_method' => $paymentMethodId,
                    'confirm' => true,
                    'capture_method' => 'manual',
                    'automatic_payment_methods' => [
                        'enabled' => true,
                        'allow_redirects' => 'never',
                    ],
                ], ['idempotency_key' => $stripeIdemBase.':pi:'.$index]);

                if ($intent->status !== 'requires_capture') {
                    throw new \Exception('Payment failed');
                }

                $authorisations[$index] = [
                    'intent_id' => $intent->id,
                    'charge_id' => $intent->latest_charge,
                    'customer_stripe_id' => $customerStripeId,
                ];

            } catch (\Stripe\Exception\CardException $e) {

                // Release anything already held for earlier lines of this sale.
                $this->releaseCardAuthorisations($authorisations);

                // ✅ Correct: Stripe card error → bubble up
                throw new \Exception($e->getError()->message);

            } catch (\Throwable $e) {

                $this->releaseCardAuthorisations($authorisations);

                // ✅ Correct: Throwable has NO getError()
                throw new \Exception($e->getMessage());
            }
        }

        return $authorisations;
    }

    /**
     * Take the held funds once the sale is safely committed.
     *
     * A capture that fails here leaves a recorded sale against an authorisation
     * that was never taken, so it is logged loudly rather than swallowed: the
     * hold lapses on Stripe's side and the merchant has to settle it by hand.
     * Throwing instead would be worse — the sale already exists.
     */
    private function captureCardAuthorisations(array $authorisations): void
    {
        foreach ($authorisations as $auth) {
            try {
                \Stripe\PaymentIntent::retrieve($auth['intent_id'])->capture();
            } catch (\Throwable $e) {
                Log::error('[POS] Stripe capture failed after the sale committed', [
                    'intent' => $auth['intent_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Release holds for a sale that was not written, so the customer is not left
     * short of available funds for a sale that does not exist.
     */
    private function releaseCardAuthorisations(array $authorisations): void
    {
        foreach ($authorisations as $auth) {
            try {
                \Stripe\PaymentIntent::retrieve($auth['intent_id'])->cancel();
            } catch (\Throwable $e) {
                Log::error('[POS] Stripe authorisation could not be released', [
                    'intent' => $auth['intent_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ---------------- Sellable check for live sales ----------------\\

    /** Products the POS sells: not deleted and not marked "Not for sale" (same as the grid). */
    protected function posSellableProducts()
    {
        return Product::query()->whereNull('deleted_at')->where('not_selling', 0);
    }

    /**
     * Lines of a live sale whose product was deleted / marked "Not for sale",
     * or whose variant was deleted or belongs to another product.
     * Returns [product_id => name] for the offending lines.
     */
    protected function unsellablePosLines(array $details): array
    {
        $productIds = collect($details)->pluck('product_id')->map(fn ($v) => (int) $v)->filter()->unique()->values();
        $variantIds = collect($details)->pluck('product_variant_id')->map(fn ($v) => (int) $v)->filter()->unique()->values();

        $sellable = $productIds->isEmpty() ? collect() : $this->posSellableProducts()
            ->whereIn('id', $productIds)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::whereIn('id', $variantIds)
            ->whereNull('deleted_at')->pluck('product_id', 'id');

        $bad = [];
        foreach ($details as $d) {
            $pid = (int) ($d['product_id'] ?? 0);
            $pvid = (int) ($d['product_variant_id'] ?? 0);
            if (! $pid) {
                continue;
            }
            if (! $sellable->has($pid) || ($pvid && (int) ($variants[$pvid] ?? 0) !== $pid)) {
                $bad[$pid] = (string) ($d['name'] ?? '');
            }
        }

        if ($bad) {
            // Fill names the payload did not carry.
            $names = Product::whereIn('id', array_keys($bad))->pluck('name', 'id');
            foreach ($bad as $pid => $name) {
                $bad[$pid] = $name !== '' ? $name : (string) ($names[$pid] ?? '#'.$pid);
            }
        }

        return $bad;
    }

    /**
     * Change Salesperson During Checkout: the user the POS asked to credit, or
     * null when the feature is off, nothing was sent, or the id is not an
     * active user. Stored in sales.seller_id — user_id stays the cashier.
     */
    private function resolvePosSellerId(Request $request): ?int
    {
        if (! $request->filled('seller_id')) {
            return null;
        }
        $settings = Setting::where('deleted_at', '=', null)->first();
        if (! (bool) ($settings->enable_pos_salesperson_switch ?? false)) {
            return null;
        }

        return User::where('id', (int) $request->seller_id)
            ->where('deleted_at', '=', null)
            ->where('statut', 1)
            ->value('id');
    }

    /**
     * Commissions: the Sales Agent picked on the POS screen (same field as
     * Create Sale). Only an active, non-deleted agent is kept; otherwise null,
     * and CommissionService falls back to the agent linked to the seller/cashier.
     */
    private function resolvePosSalesAgentId(Request $request): ?int
    {
        if (! $request->filled('sales_agent_id')) {
            return null;
        }

        return SalesAgent::where('id', (int) $request->sales_agent_id)
            ->where('is_active', true)
            ->value('id');
    }

    /**
     * Commissions for a completed POS sale (idempotent, never blocks the sale).
     * Takes the transaction's result as-is: a closure that bailed out with a
     * JSON error response is skipped instead of throwing on ->id.
     */
    private function calculatePosCommissions($sale): void
    {
        if (! $sale instanceof Sale) {
            return;
        }
        try {
            // Fresh row: paid_amount/payment_statut were written to another
            // instance inside the transaction ("Paid Amount" rules need them).
            $fresh = $sale->fresh();
            if ($fresh && $fresh->statut === 'completed') {
                app(\App\Services\CommissionService::class)->calculateForSale($fresh);
            }
        } catch (\Throwable $e) {
            \Log::warning('Commission calculation failed (non-blocking on POS): '.$e->getMessage(), ['sale_id' => $sale->id]);
        }
    }

    /**
     * POST pos/create_pos. Sales replayed from a till's offline queue (they
     * carry `offline_id`) are also recorded for Sales > Offline Sales —
     * synced, or rejected with the reason — and a sale a manager discarded
     * there is refused (`discarded: true`) so the till drops its copy.
     * Every other sale goes straight to createPosSale(), unchanged.
     */
    public function CreatePOS(Request $request)
    {
        if (! OfflineSaleRecorder::applies($request)) {
            return $this->createPosSale($request);
        }

        $recorded = OfflineSaleRecorder::find($request);
        if ($recorded && $recorded->status === PosOfflineSale::STATUS_DISCARDED) {
            return response()->json([
                'success' => false,
                'discarded' => true,
                'message' => 'This offline sale was discarded by a manager.',
            ], 409);
        }

        try {
            $response = $this->createPosSale($request);
        } catch (ValidationException $e) {
            OfflineSaleRecorder::failed($request, OfflineSaleRecorder::validationMessage($e), $e->status);
            throw $e;
        } catch (AuthorizationException $e) {
            OfflineSaleRecorder::failed($request, $e->getMessage() ?: 'This action is unauthorized.', 403);
            throw $e;
        } catch (HttpExceptionInterface $e) {
            OfflineSaleRecorder::failed($request, $e->getMessage() ?: 'HTTP '.$e->getStatusCode(), $e->getStatusCode());
            throw $e;
        } catch (\Throwable $e) {
            OfflineSaleRecorder::failed($request, $e->getMessage(), 500);
            throw $e;
        }

        OfflineSaleRecorder::fromResponse($request, $response);

        return $response;
    }

    protected function createPosSale(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        // $request, not request(): a manager's retry from Sales > Offline Sales
        // replays the stored payload as its own Request object.
        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account. Set per line (a
        // wildcard closure would be skipped for a missing account) and never
        // for a Customer Wallet line: that money does not land in an account.
        $accountRules = [];
        $walletService = app(\App\Services\CustomerWallet\CustomerWalletService::class);
        foreach ((array) $request->input('payments', []) as $index => $line) {
            if (! $walletService->isWalletMethod(is_array($line) ? ($line['payment_method_id'] ?? null) : null)) {
                $accountRules['payments.'.$index.'.account_id'] = $this->paymentAccountRule();
            }
        }
        $request->validate([
            'client_id' => 'required',
            'warehouse_id' => 'required',
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric',
            'payments.*.payment_method_id' => 'required',
        ] + $accountRules);

        // Optional idempotency key generated on the frontend (crypto.randomUUID).
        // When provided, we must:
        //  - avoid creating duplicate sales (and therefore duplicate stock/payments)
        //  - treat a repeat request with the same UUID as a success that returns the existing sale
        // This MUST run before the stock preconditions below: a replay of a
        // sale that already succeeded (response lost, offline queue re-sent)
        // has already consumed that stock, so those checks would reject it
        // with a 422 and the client would report a failure for a sale that
        // exists.
        $saleUuid = $request->input('sale_uuid');

        if ($saleUuid) {
            $existing = Sale::where('sale_uuid', $saleUuid)->first();
            if ($existing) {
                // Short‑circuit: this sale was already created earlier with the same UUID.
                // Do NOT run stock/payment logic again; simply return a success payload
                // compatible with the original response shape.
                return response()->json([
                    'success' => true,
                    'id' => $existing->id,
                    'qbo_sync' => 'skipped',
                    'updated_stock' => [],
                    'server_time' => now()->toIso8601String(),
                ], 200);
            }
        }

        // A live sale must not include products deleted or marked "Not for
        // sale" since they were rung up (e.g. a cart restored after a refresh).
        // Offline replays are exempt: those goods were already handed over.
        if (! $request->filled('offline_id')) {
            $unsellable = $this->unsellablePosLines((array) $request->input('details', []));
            if ($unsellable) {
                return response()->json([
                    'message' => __('messages.SomeProductsNotFound').' '.implode(', ', $unsellable),
                    'unavailable_product_ids' => array_keys($unsellable),
                ], 422);
            }
        }

        // The role's discount limit and the minimum price after the order
        // discount — for offline replays too: offline_id is client-supplied,
        // and the register applies the same check before payment offline, so
        // only a tampered sale (or a limit lowered meanwhile) stops here; the
        // offline queue then shows it under Sales > Offline Sales.
        $violation = \App\Services\PosDiscountLimit::violation(
            $request->user('api'),
            (array) $request->input('details', []),
            $request->input('discount', 0),
            $request->input('discount_Method', '2')
        );
        if ($violation) {
            return response()->json(['message' => $violation], 422);
        }

        // Offline-queued sales carry the moment they were rung up; book the
        // sale (and its payments/lines) on that date rather than the sync time.
        $saleAt = $this->resolveOfflineSaleTimestamp($request, $saleUuid);

        // Every managed-stock line (packs included) must be covered by stock
        // unless Settings > Features > Allow overselling is on.
        $this->assertSaleStockSufficient($request);

        // Overpayment is normal retail: the customer hands over a larger note and
        // takes the difference back as change. It can only come out of a cash
        // tender though — there is no change from a card — so on a split payment
        // the excess has to be covered by one cash line. Whichever line absorbs
        // it is recorded here and applied when the payments are written below.
        $totalPaid = collect($request->payments)->sum('amount');
        $overpayment = round($totalPaid - (float) $request->GrandTotal, 4);
        $changeLineIndex = null;

        if ($overpayment > 0) {
            if (count($request->payments) === 1) {
                // Single tender: unchanged behaviour, that one line absorbs it.
                $changeLineIndex = array_key_first($request->payments);
            } else {
                $cashMethodIds = PaymentMethod::cashMethodIds();

                foreach ($request->payments as $index => $payment) {
                    $isCash = in_array((int) ($payment['payment_method_id'] ?? 0), $cashMethodIds, true);

                    if ($isCash && (float) ($payment['amount'] ?? 0) >= $overpayment) {
                        $changeLineIndex = $index;
                        break;
                    }
                }
            }

            // Nothing can give the change back: a card-only overpayment, or a
            // cash line too small to cover it.
            if ($changeLineIndex === null) {
                return response()->json([
                    'message' => 'Total Paid Exceeds Grand Total',
                    'change_return' => $overpayment,
                ], 422);
            }
        }

        // Re-evaluate promotions server-side so the client can't fabricate a discount.
        // The frontend sends the cart context; we recompute and persist what *we* see.
        $promotionResult = app(PromotionEngine::class)->applyTo([
            'warehouse_id' => (int) $request->warehouse_id,
            'subtotal'     => (float) ($request->input('promotion_subtotal', 0)),
            'item_count'   => (int) ($request->input('promotion_item_count', 0)),
            'product_ids'  => array_map('intval', (array) $request->input('promotion_product_ids', [])),
            'product_subtotals' => (array) $request->input('promotion_product_subtotals', []),
            'code'         => $request->input('promotion_code'),
            'client_id'    => $request->input('client_id'),
            'now'          => Carbon::now(),
        ]);
        $promotionDiscount = (float) ($promotionResult['total_discount'] ?? 0);
        $promotionCodeApplied = trim((string) $request->input('promotion_code', '')) ?: null;
        $appliedPromotions = $promotionResult['applied'] ?? [];

        // Customer-wallet tender: refuse early (before a card is authorised)
        // when the wallet is off, frozen or short. The debit itself happens
        // inside the transaction below, under a row lock.
        $walletService = app(\App\Services\CustomerWallet\CustomerWalletService::class);
        $walletPayTotal = 0.0;
        foreach ($request->payments as $index => $payment) {
            if ($walletService->isWalletMethod($payment['payment_method_id'] ?? null) && (float) ($payment['amount'] ?? 0) > 0) {
                $walletPayTotal += (float) $payment['amount'] - (($changeLineIndex !== null && $index === $changeLineIndex) ? $overpayment : 0);
            }
        }
        if ($walletPayTotal > 0) {
            $summary = $walletService->summaryFor((int) $request->client_id);
            if (! $summary['enabled']) {
                return response()->json(['success' => false, 'code' => 'wallet_disabled', 'message' => 'Customer wallet is not enabled.'], 422);
            }
            if ($summary['status'] !== 'active') {
                return response()->json(['success' => false, 'code' => 'wallet_frozen', 'message' => 'This customer wallet is frozen.'], 422);
            }
            if (! $summary['allow_negative'] && round($walletPayTotal, 2) > round((float) $summary['balance'], 2)) {
                return response()->json([
                    'success' => false,
                    'code' => 'wallet_insufficient_balance',
                    'message' => 'Insufficient wallet balance.',
                    'balance' => $summary['balance'],
                    'required' => round($walletPayTotal, 2),
                ], 422);
            }
        }

        // Lines paid on a POS device (Ingenico etc.) carry the approved
        // terminal transaction; check them before anything is written.
        $terminalCharges = TerminalSaleLinker::claim($request, $changeLineIndex);

        // Card lines are authorised with Stripe BEFORE the transaction opens, so a
        // network round trip never holds database row locks, and a sale that fails
        // to commit never leaves a captured charge behind on the customer's card.
        try {
            $cardAuthorisations = $this->authoriseCardPayments($request, $changeLineIndex, $overpayment);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        try {
            $sale = \DB::transaction(function () use ($request, $totalPaid, $saleUuid, $saleAt, $promotionDiscount, $promotionCodeApplied, $appliedPromotions, $overpayment, $changeLineIndex, $cardAuthorisations, $terminalCharges) {
                $helpers = new helpers;
                $user = Auth::user();
                // New way: Check user's record_view field (user-level boolean)
                // Backward compatibility: If record_view is null, fall back to role permission check
                $view_records = $user->hasRecordView();
                $order = new Sale;

                $order->is_pos = 1;
                $order->date = $saleAt->copy();
                $order->time = $saleAt->toTimeString();
                $order->Ref = app('App\Http\Controllers\SalesController')->getNumberOrder();
                $order->client_id = $request->client_id;
                $order->warehouse_id = $request->warehouse_id;
                $order->tax_rate = $request->tax_rate;
                $order->TaxNet = $request->TaxNet;
                $order->discount = $request->discount;
                $order->promotion_discount = $promotionDiscount;
                $order->promotion_code = $promotionCodeApplied;
                // Ensure discount_Method is saved correctly: '1' for percentage, '2' for fixed
                $order->discount_Method = $request->has('discount_Method') ? (string) $request->discount_Method : '2';
                $order->shipping = $request->shipping;
                $order->GrandTotal = $request->GrandTotal;
                $order->notes = $request->notes;
                $order->statut = 'completed';
                $order->payment_statut = 'unpaid';
                $order->user_id = Auth::user()->id;
                // POS "Change Salesperson": credit another active user for the
                // sale while user_id stays the cashier who rang it up. Only
                // honoured while the Features switch is on, and only for a
                // real, active, non-deleted user.
                $order->seller_id = $this->resolvePosSellerId($request);
                $order->sales_agent_id = $this->resolvePosSalesAgentId($request);
                // Multi-Currency snapshot (NULL/NULL = base currency); every
                // amount on the sale is stored in the base currency.
                $docCurrency = helpers::resolve_request_currency($request);
                $order->currency_id = $docCurrency['currency_id'];
                $order->exchange_rate = $docCurrency['exchange_rate'];
                if (! empty($saleUuid)) {
                    $order->sale_uuid = $saleUuid;
                }
                $order->save();

                // Record one promotion_usages row per applied promotion so future
                // calls can enforce total + per-customer caps and feed reports.
                foreach ($appliedPromotions as $applied) {
                    PromotionUsage::create([
                        'promotion_id'    => (int) $applied['id'],
                        'sale_id'         => $order->id,
                        'client_id'       => $request->client_id ?: null,
                        'warehouse_id'    => $order->warehouse_id ?: null,
                        'discount_amount' => (float) $applied['amount'],
                        'code'            => $applied['code'] ?? $promotionCodeApplied,
                        'used_at'         => Carbon::now(),
                    ]);
                }

                $data = $request['details'];
                $total_points_earned = 0;

                foreach ($data as $key => $value) {
                    $product = Product::find($value['product_id']);
                    $isService = isset($value['product_type']) && $value['product_type'] === 'is_service';

                    // Resolve sale unit:
                    //  - Prefer explicit sale_unit_id from payload
                    //  - Fallback to product.unit_sale_id (unitSale relation) for backward compatibility
                    $unit = null;
                    if (! $isService) {
                        if (isset($value['sale_unit_id']) && $value['sale_unit_id'] !== null && $value['sale_unit_id'] !== '') {
                            $unit = Unit::where('id', $value['sale_unit_id'])->first();
                        }

                        if (! $unit) {
                            $productWithUnit = Product::with('unitSale')
                                ->where('id', $value['product_id'])
                                ->first();
                            if ($productWithUnit && $productWithUnit->unitSale) {
                                $unit = $productWithUnit->unitSale;
                                $value['sale_unit_id'] = $unit->id;
                            }
                        }
                    }

                    $total_points_earned += $value['quantity'] * $product->points;

                    // Multi-Pack Selling: a selected pack multiplies the base-unit
                    // stock movement. Defaults to 1 so non-pack lines are unchanged.
                    $packMultiplier = isset($value['pack_multiplier']) && (float) $value['pack_multiplier'] > 0
                        ? (float) $value['pack_multiplier'] : 1;
                    $packQty = $value['quantity'] * $packMultiplier;

                    $baseDate = $saleAt->copy();
                    $warrantyGuarantee = SaleDetail::computeWarrantyGuaranteeDates($product, $baseDate);

                    $orderDetails[] = array_merge([
                        'date' => $saleAt->copy(),
                        'sale_id' => $order->id,
                        'sale_unit_id' => $value['sale_unit_id'],
                        'quantity' => $value['quantity'],
                        'product_id' => $value['product_id'],
                        'product_variant_id' => $value['product_variant_id'],
                        'total' => $value['subtotal'],
                        'price' => $value['Unit_price'],
                        'TaxNet' => $value['tax_percent'],
                        'tax_method' => $value['tax_method'],
                        'discount' => $value['discount'],
                        'discount_method' => $value['discount_Method'],
                        'imei_number' => $this->resolveImeiString($value),
                        'price_type' => isset($value['price_type']) ? $value['price_type'] : 'retail',
                        'product_pack_id' => isset($value['product_pack_id']) && $value['product_pack_id'] ? (int) $value['product_pack_id'] : null,
                        'pack_multiplier' => $packMultiplier,
                        'pack_name' => $value['pack_name'] ?? null,
                    ], $warrantyGuarantee);

                    // Stock deduction only applies to non-service items.
                    // If unit or product_warehouse cannot be resolved, we skip stock adjustment
                    // but still create the sale; this prevents hard failures for legacy/offline data.
                    if (! $isService && $unit) {
                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->lockForUpdate()->first();
                        } elseif (app(ComboStockService::class)->moveComponents((int) $value['product_id'], (int) $order->warehouse_id, -ComboStockService::baseQty($packQty, $unit))) {
                            // Virtual combo: its components were taken instead of its own row.
                            $product_warehouse = null;
                        } else {
                            $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->lockForUpdate()->first();
                        }

                        if ($product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte -= $packQty / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $packQty * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }

                SaleDetail::insert($orderDetails);

                // Pharmacy: for batch-tracked products in the cart, consume batches FEFO.
                // We keep the bulk insert above for POS speed; only when at least one line is
                // batch-tracked do we query back the persisted details and invoke BatchService.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $productIds = collect($data)->pluck('product_id')->filter()->unique()->values()->all();
                    $hasBatchTracked = ! empty($productIds) && Product::whereIn('id', $productIds)
                        ->where('is_batch_tracked', true)
                        ->exists();

                    if ($hasBatchTracked) {
                        // Insert order is preserved in auto-increment id order; match by ordinal.
                        $persistedDetails = SaleDetail::where('sale_id', $order->id)
                            ->orderBy('id')
                            ->get();
                        $order->warehouse_id = (int) $request->warehouse_id;
                        $batchService->applyForSaleWithAutoFallback(
                            $order,
                            array_values($data),
                            $persistedDetails
                        );
                    }
                }

                // Serial / IMEI: mark selected serials as sold.
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $order->warehouse_id = (int) $request->warehouse_id;
                    $persistedSerials = SaleDetail::where('sale_id', $order->id)
                        ->orderBy('id', 'asc')
                        ->get();
                    foreach (array_values($data) as $i => $row) {
                        $detail = $persistedSerials->get($i);
                        if (! $detail) {
                            continue;
                        }
                        $serialService->sellOnSale($order, $detail, $row['serial_numbers'] ?? null);
                    }
                }

                // Software License products: claim / issue one key per unit sold.
                \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWrite($order->id);

                $sale = Sale::findOrFail($order->id);
                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($sale);

                if (! $view_records) {
                    // Check If User->id === sale->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $sale);
                }

                foreach ($request->payments as $index => $payment) {
                    if (isset($payment['amount']) && $payment['amount'] > 0) {
                        // Prefer per-line account selection; no global account fallback
                        $accountId = $payment['account_id'] ?? null;

                        $originalAmount = $payment['amount'];
                        $paymentAmount = $originalAmount;
                        $changeReturn = 0;

                        // The tender chosen above gives the change back, so only
                        // the part that stays in the drawer is recorded as paid.
                        if ($changeLineIndex !== null && $index === $changeLineIndex) {
                            $paymentAmount = $originalAmount - $overpayment;
                            $changeReturn = $overpayment;
                        }

                        // Card lines were authorised with Stripe before this
                        // transaction opened; here we only read the result.
                        $customerStripeId = $cardAuthorisations[$index]['customer_stripe_id'] ?? null;
                        $stripeChargeId = $cardAuthorisations[$index]['charge_id'] ?? null;


                        // Customer-wallet tender: debit the wallet, no cash account.
                        $wallets = app(\App\Services\CustomerWallet\CustomerWalletService::class);
                        $fromWallet = $wallets->isWalletMethod($payment['payment_method_id'] ?? null);
                        $paymentRef = app('App\\Http\\Controllers\\PaymentSalesController')->getNumberOrder();
                        $walletTxId = null;
                        if ($fromWallet) {
                            $accountId = null;
                            $walletTxId = $wallets->payFromWallet((int) $order->client_id, (float) $paymentAmount, 'sale_payment', [
                                'reference_type' => Sale::class,
                                'reference_id' => $order->id,
                                'note' => 'POS sale '.$order->Ref.' · '.$paymentRef,
                                'date' => $saleAt->copy(),
                                'user_id' => Auth::id(),
                            ])->id;
                        }

                        $paymentSale = PaymentSale::create([
                            'sale_id' => $order->id,
                            'account_id' => $accountId,
                            'Ref' => $paymentRef,
                            'date' => $saleAt->copy(),
                            'payment_method_id' => $payment['payment_method_id'],
                            'montant' => $paymentAmount,
                            'change' => $fromWallet ? 0 : $changeReturn,
                            'notes' => $request['payment_note'] ?? null,
                            'user_id' => Auth::user()->id,
                        ] + \App\Services\CustomerWallet\CustomerWalletService::txColumn($walletTxId));

                        TerminalSaleLinker::link($terminalCharges, $index, $order, $paymentSale);

                        if (($payment['payment_method_id'] == 1 || $payment['payment_method_id'] == '1') && ! isset($terminalCharges[$index])) {
                            PaymentWithCreditCard::create([
                                'customer_id' => $request->client_id,
                                'payment_id' => $paymentSale->id,
                                'customer_stripe_id' => $customerStripeId,
                                'charge_id' => $stripeChargeId,
                            ]);
                        }

                        Account::adjustBalance($accountId, $paymentAmount);
                    }
                }

                $totalPaidAdjusted = min($totalPaid, $request->GrandTotal);

                // 🪙 Points logic
                $client = Client::find($request->client_id);
                $used_points = $request->used_points ?? 0;
                $discount_from_points = $request->discount_from_points ?? 0;
                $earned_points = 0;

                if ($client && ($client->is_royalty_eligible == 1 || $client->is_royalty_eligible || $client->is_royalty_eligible === 1)) {

                    // Deduct used points if valid
                    if ($used_points > 0 && $client->points >= $used_points) {
                        $client->decrement('points', $used_points);
                    }

                    // Earn points
                    $earned_points = $total_points_earned;

                    $client->increment('points', $earned_points);

                    $order_used_points = $used_points;
                    $order_earned_points = $earned_points;
                    $order_discount_from_points = $discount_from_points;
                } else {
                    $order_used_points = 0;
                    $order_earned_points = 0;
                    $order_discount_from_points = 0;
                }

                $order->update([
                    'used_points' => $order_used_points,
                    'earned_points' => $order_earned_points,
                    'discount_from_points' => $order_discount_from_points,
                    'paid_amount' => $totalPaidAdjusted,
                    'payment_statut' => Sale::paymentStatusFor($order->GrandTotal, $totalPaidAdjusted),
                ]);

                // If converting from a draft, remove the draft after successful sale creation
                if ($request->filled('draft_sale_id')) {
                    try {
                        $draft = DraftSale::find($request->draft_sale_id);
                        if ($draft) {
                            $draft->details()->delete();
                            $draft->update(['deleted_at' => Carbon::now()]);
                        }
                    } catch (\Throwable $e) {
                        // best-effort cleanup; do not fail the sale creation on draft cleanup issues
                    }
                }

                return $order;

            }, 10);

            // Committed: take the money that was only held until now.
            $this->captureCardAuthorisations($cardAuthorisations);

        } catch (\Throwable $e) {
            // Nothing was written, so let the holds go rather than leaving the
            // customer short of funds for a sale that does not exist. This also
            // covers the idempotent-duplicate path below: the request that won
            // the race carries its own authorisation.
            $this->releaseCardAuthorisations($cardAuthorisations);

            // If a concurrent request created the same sale with the same UUID, the unique
            // constraint on sale_uuid will trigger here. In that case, treat this as a
            // successful, idempotent result by returning the already‑created sale instead
            // of an error. This avoids duplicate stock deduction and payment creation.
            if ($saleUuid) {
                try {
                    $existing = Sale::where('sale_uuid', $saleUuid)->first();
                    if ($existing) {
                        return response()->json([
                            'success' => true,
                            'id' => $existing->id,
                            'qbo_sync' => 'skipped',
                            'updated_stock' => [],
                            'server_time' => now()->toIso8601String(),
                        ], 200);
                    }
                } catch (\Throwable $lookupError) {
                    // Fallback to original behavior below if lookup fails for any reason.
                }
            }

            if ($e instanceof \App\Services\SupplierWallet\WalletException) {
                return $e->toResponse();
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // ---------- AFTER COMMIT: sales-agent commissions (best effort) ----------
        $this->calculatePosCommissions($sale);

        // ---------- AFTER COMMIT: ZATCA Phase 2 reporting (best effort) ----------
        try {
            \App\Jobs\SubmitDocumentToZatca::dispatch(\App\Models\Sale::class, $sale->id)->afterCommit();
        } catch (\Throwable $e) {
            \Log::warning('ZATCA submit dispatch failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);
        }

        // ---------- AFTER COMMIT: FBR Digital Invoicing (best effort) ----------
        try {
            \App\Jobs\SubmitSaleToFbr::dispatch(\App\Models\Sale::class, $sale->id)->afterCommit();
        } catch (\Throwable $e) {
            \Log::warning('FBR submit dispatch failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);
        }

        // ---------- AFTER COMMIT: AGT e-invoicing, Angola (best effort) ----------
        try {
            \App\Jobs\SubmitDocumentToAgt::dispatch(\App\Models\Sale::class, $sale->id)->afterCommit();
        } catch (\Throwable $e) {
            \Log::warning('AGT submit dispatch failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);
        }

        // ---------- AFTER COMMIT: QBO sync (best effort, non-blocking) ----------
        $qboSync = 'skipped';
        try {
            if (class_exists(\App\Jobs\SyncSaleToQuickBooks::class)) {
                \App\Jobs\SyncSaleToQuickBooks::dispatch($sale->id)->afterCommit();
                $qboSync = 'queued';
            } else {
                $sale->load(['saleDetails.product', 'client']);
                /** @var \App\Services\QuickBooksService $qb */
                $qb = app(\App\Services\QuickBooksService::class);

                $res = ! empty($sale->quickbooks_invoice_id)
                    ? $qb->updateInvoice($sale)
                    : $qb->createInvoice($sale);

                if (! ($res['ok'] ?? false)) {
                    \Log::warning('QBO POS sync failed', [
                        'sale_id' => $sale->id,
                        'error' => $res['error'] ?? 'unknown',
                        'http' => $res['http'] ?? null,
                        'body' => $res['body'] ?? null,
                    ]);
                    $sale->update([
                        'quickbooks_sync_error' => substr(($res['error'] ?? '').' '.($res['body'] ?? ''), 0, 65000),
                    ]);
                    $qboSync = 'failed';
                } else {
                    $sale->update([
                        'quickbooks_invoice_id' => $sale->quickbooks_invoice_id ?: ($res['id'] ?? null),
                        'quickbooks_realm_id' => $res['realm'] ?? ($sale->quickbooks_realm_id ?? null),
                        'quickbooks_synced_at' => now(),
                        'quickbooks_sync_error' => null,
                    ]);
                    $qboSync = 'ok';
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('QuickBooks sync failed (non-blocking on POS): '.$e->getMessage(), [
                'sale_id' => $sale->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $qboSync = 'failed';
        }

        $emailError = null;
        $smsError = null;

        try {
            if ($request->send_email) {
                $this->Send_Email($sale->id);
            }
        } catch (\Throwable $e) {
            \Log::error('Send_Email failed: '.$e->getMessage());
            $emailError = $e->getMessage();
        }

        try {
            if ($request->send_sms) {
                $this->Send_SMS($sale->id);
            }
        } catch (\Throwable $e) {
            \Log::error('Send_SMS failed: '.$e->getMessage());
            $smsError = $e->getMessage();
        }

        // ---------- Send to kitchen (optional, best-effort, non-blocking) ----------
        // Only when the feature is enabled in settings AND the salesperson chose "Send to Kitchen".
        // "none" (Save Without Sending) and "later" (Send Later) create no ticket here, so the
        // existing sales workflow is unchanged when the feature is off or not requested.
        try {
            $kitchenAction = (string) $request->input('kitchen_action', 'none');
            $kitchenEnabled = (bool) optional(\App\Models\Setting::current())->enable_kitchen_display;

            if ($kitchenEnabled && $kitchenAction === 'send') {
                KitchenOrderController::createForSale($sale->id, $request->notes);
            }
        } catch (\Throwable $e) {
            \Log::warning('Send to kitchen failed (non-blocking on POS): '.$e->getMessage(), [
                'sale_id' => $sale->id,
            ]);
        }

        // ---------- Collect updated stock for sold lines ----------
        // After the sale transaction has committed we query fresh stock for
        // the items that were just sold so the POS UI can patch its in-memory
        // products list without re-fetching the entire catalog.
        $updatedStock = [];
        try {
            $warehouseId = $sale->warehouse_id;
            $seen = [];
            $rows = collect();
            foreach ((array) $request->details as $line) {
                if (!is_array($line) || !isset($line['product_id'])) continue;
                $pid = $line['product_id'];
                $vid = isset($line['product_variant_id']) ? $line['product_variant_id'] : null;
                $key = $pid . ':' . ($vid === null || $vid === '' ? 'null' : $vid);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $q = product_warehouse::where('warehouse_id', $warehouseId)
                    ->where('product_id', $pid)
                    ->whereNull('deleted_at')
                    ->with('product.unitSale');
                if ($vid !== null && $vid !== '') {
                    $q->where('product_variant_id', $vid);
                } else {
                    $q->whereNull('product_variant_id');
                }
                if ($pw = $q->first()) {
                    $rows->push($pw);
                }
            }
            foreach ($this->withVirtualComboRows($rows, $warehouseId) as $pw) {
                $formatted = $this->formatStockRow($pw);
                if ($formatted !== null) {
                    $updatedStock[] = $formatted;
                }
            }
        } catch (\Throwable $e) {
            $updatedStock = [];
        }

        $response = [
            'success' => true,
            'id' => $sale->id,
            'qbo_sync' => $qboSync,
            'updated_stock' => $updatedStock,
            'server_time' => now()->toIso8601String(),
        ];

        if ($emailError) {
            $response['email_error'] = $emailError;
        }

        if ($smsError) {
            $response['sms_error'] = $smsError;
        }

        return response()->json($response, 200);
    }

    /**
     * GET pos/wallet-balance/{client}: the customer's wallet for the POS
     * payment modal (balance hint on the "Customer Wallet" tender). Answers
     * {enabled:false} rather than an error when the feature is off.
     */
    public function walletBalance(Request $request, $clientId)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        return response()->json(app(\App\Services\CustomerWallet\CustomerWalletService::class)->summaryFor((int) $clientId));
    }

    public function Send_Email($id)
    {

        // sale
        $sale = Sale::with('client')->where('deleted_at', '=', null)->findOrFail($id);

        // Multi-Currency: these tags describe one sale, so use its document
        // currency (symbol + converted amounts). Base sales keep rate 1.
        $docCurrency = helpers::Get_Document_Currency($sale);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::current();

        // the custom msg of sale
        $emailMessage = EmailMessage::getForLocale('sale');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }

        // Tags
        $invoice_url = $sale->shareUrl();
        $invoice_number = $sale->Ref;

        $total_amount = helpers::with_currency($currency, helpers::format_price($sale->GrandTotal * $docCurrency['rate'], null, 'comma_dot'));
        $paid_amount = helpers::with_currency($currency, helpers::format_price($sale->paid_amount * $docCurrency['rate'], null, 'comma_dot'));
        $due_amount = helpers::with_currency($currency, helpers::format_price(($sale->GrandTotal - $sale->paid_amount) * $docCurrency['rate'], null, 'comma_dot'));

        $contact_name = $sale['client']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $sale['client']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', e((string) $contact_name), $message_body);
        $message_body = str_replace('{business_name}', e((string) $business_name), $message_body);
        $message_body = str_replace('{invoice_url}', $invoice_url, $message_body);
        $message_body = str_replace('{invoice_number}', $invoice_number, $message_body);

        $message_body = str_replace('{total_amount}', $total_amount, $message_body);
        $message_body = str_replace('{paid_amount}', $paid_amount, $message_body);
        $message_body = str_replace('{due_amount}', $due_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);

    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS($id)
    {

        // sale
        $sale = Sale::with('client')->where('deleted_at', '=', null)->findOrFail($id);

        // Multi-Currency: these tags describe one sale, so use its document
        // currency (symbol + converted amounts). Base sales keep rate 1.
        $docCurrency = helpers::Get_Document_Currency($sale);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::current();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        // the custom msg of sale
        $smsMessage = SMSMessage::getForLocale('sale');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $invoice_url = $sale->shareUrl();
        $invoice_number = $sale->Ref;

        $total_amount = helpers::with_currency($currency, helpers::format_price($sale->GrandTotal * $docCurrency['rate'], null, 'comma_dot'));
        $paid_amount = helpers::with_currency($currency, helpers::format_price($sale->paid_amount * $docCurrency['rate'], null, 'comma_dot'));
        $due_amount = helpers::with_currency($currency, helpers::format_price(($sale->GrandTotal - $sale->paid_amount) * $docCurrency['rate'], null, 'comma_dot'));

        $contact_name = $sale['client']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $sale['client']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        if (! $default_sms_gateway) {
            return response()->json(['status' => 'error', 'message' => 'SMS gateway is not configured'], 500);
        }

        // credentials are resolved from the tenant's own sms_settings table
        try {
            app(\App\Services\SmsOtpSender::class)->sendVia($default_sms_gateway->title, $receiverNumber, $message_text);
        } catch (\Throwable $e) {
            Log::error('SMS Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true]);

    }

    // ------------- get_draft_sales -----------\\

    public function get_draft_sales(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }
        // How many items do you want to display.
        $perPage = $request->limit;

        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = 'id';
        $dir = 'DESC';
        $helpers = new helpers;

        $data = [];

        // Check If User Has Permission View  All Records
        $draft_sales = DraftSale::with('client', 'warehouse', 'user')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $draft_sales->whereIn('warehouse_id', $warehouse_ids);
        }

        $totalRows = $draft_sales->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $drafts = $draft_sales->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($drafts as $draft) {

            $item['id'] = $draft['id'];
            $item['date'] = $draft['date'];
            $item['Ref'] = $draft['Ref'];
            $item['warehouse_name'] = $draft['warehouse']['name'];
            $item['client_name'] = $draft['client']['name'];
            $item['GrandTotal'] = number_format($draft['GrandTotal'], helpers::price_decimals(), '.', '');
            $item['actions'] = '';

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'draft_sales' => $data,
        ]);
    }

    // ------------ Create Draft --------------\\

    public function CreateDraft(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        request()->validate([
            'client_id' => 'required',
            'warehouse_id' => 'required',
        ]);

        \DB::transaction(function () use ($request) {
            $helpers = new helpers;
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check

            // If draft_sale_id present, update existing draft; otherwise create new
            if ($request->filled('draft_sale_id')) {
                $order = DraftSale::whereNull('deleted_at')->findOrFail($request->draft_sale_id);
               
                // Update header
                $order->update([
                    'date' => Carbon::now(),
                    'client_id' => $request->client_id,
                    'warehouse_id' => $request->warehouse_id,
                    'tax_rate' => $request->tax_rate,
                    'TaxNet' => $request->TaxNet,
                    'discount' => $request->discount,
                    // Ensure discount_Method is saved correctly: '1' for percentage, '2' for fixed
                    'discount_Method' => $request->has('discount_Method') ? (string) $request->discount_Method : '2',
                    'shipping' => $request->shipping,
                    'GrandTotal' => $request->GrandTotal,
                    'user_id' => Auth::user()->id,
                ] + helpers::resolve_request_currency($request, $order->currency_id, $order->exchange_rate));

                // Replace details
                $order->details()->delete();
                $data = $request['details'];
                $orderDetails = [];
                foreach ($data as $key => $value) {
                    $orderDetails[] = [
                        'date' => Carbon::now(),
                        'draft_sale_id' => $order->id,
                        'sale_unit_id' => $value['sale_unit_id'],
                        'quantity' => $value['quantity'],
                        'product_id' => $value['product_id'],
                        'product_variant_id' => $value['product_variant_id'],
                        'total' => $value['subtotal'],
                        'price' => $value['Unit_price'],
                        'TaxNet' => $value['tax_percent'],
                        'tax_method' => $value['tax_method'],
                        'discount' => $value['discount'],
                        'discount_method' => $value['discount_Method'],
                        'imei_number' => $this->resolveImeiString($value),
                        'price_type' => isset($value['price_type']) ? $value['price_type'] : 'retail',
                        'product_pack_id' => isset($value['product_pack_id']) && $value['product_pack_id'] ? (int) $value['product_pack_id'] : null,
                        'pack_multiplier' => isset($value['pack_multiplier']) && (float) $value['pack_multiplier'] > 0 ? (float) $value['pack_multiplier'] : 1,
                        'pack_name' => $value['pack_name'] ?? null,
                    ];
                }
                if (! empty($orderDetails)) {
                    DraftSaleDetail::insert($orderDetails);
                }
            } else {
                $order = new DraftSale;
                $order->date = Carbon::now();
                $order->Ref = $this->getNumberOrderDraft();
                $order->client_id = $request->client_id;
                $order->warehouse_id = $request->warehouse_id;
                $order->tax_rate = $request->tax_rate;
                $order->TaxNet = $request->TaxNet;
                $order->discount = $request->discount;
                // Ensure discount_Method is saved correctly: '1' for percentage, '2' for fixed
                $order->discount_Method = $request->has('discount_Method') ? (string) $request->discount_Method : '2';
                $order->shipping = $request->shipping;
                $order->GrandTotal = $request->GrandTotal;
                $order->user_id = Auth::user()->id;
                // Multi-Currency snapshot so the held order resumes in the
                // same currency (NULL = base).
                $draftCurrency = helpers::resolve_request_currency($request);
                $order->currency_id = $draftCurrency['currency_id'];
                $order->exchange_rate = $draftCurrency['exchange_rate'];
                $order->save();

                $data = $request['details'];
                $orderDetails = [];
                foreach ($data as $key => $value) {
                    $orderDetails[] = [
                        'date' => Carbon::now(),
                        'draft_sale_id' => $order->id,
                        'sale_unit_id' => $value['sale_unit_id'],
                        'quantity' => $value['quantity'],
                        'product_id' => $value['product_id'],
                        'product_variant_id' => $value['product_variant_id'],
                        'total' => $value['subtotal'],
                        'price' => $value['Unit_price'],
                        'TaxNet' => $value['tax_percent'],
                        'tax_method' => $value['tax_method'],
                        'discount' => $value['discount'],
                        'discount_method' => $value['discount_Method'],
                        'imei_number' => $this->resolveImeiString($value),
                        'price_type' => isset($value['price_type']) ? $value['price_type'] : 'retail',
                        'product_pack_id' => isset($value['product_pack_id']) && $value['product_pack_id'] ? (int) $value['product_pack_id'] : null,
                        'pack_multiplier' => isset($value['pack_multiplier']) && (float) $value['pack_multiplier'] > 0 ? (float) $value['pack_multiplier'] : 1,
                        'pack_name' => $value['pack_name'] ?? null,
                    ];
                }
                if (! empty($orderDetails)) {
                    DraftSaleDetail::insert($orderDetails);
                }
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ remove_draft_sale -------------\\

    public function remove_draft_sale(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        // POS Settings > manager approval: deleting a held draft throws its items
        // away, so it needs a manager PIN (pos/voids/approve with this draft_id).
        $user = $request->user('api');
        if (\App\Services\Pos\PosApprovalPin::requiredFor($user)
            && ! \App\Services\Pos\PosApprovalPin::consumeDraftDelete($user, (int) $id)) {
            return response()->json(['message' => __('A manager must approve deleting this draft.')], 422);
        }

        \DB::transaction(function () use ($id, $request) {

            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $draft = DraftSale::findOrFail($id);

            $draft->details()->delete();
            $draft->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ submit_sale_from_draft --------------\\

    public function submit_sale_from_draft(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        request()->validate([
            'client_id' => 'required',
            'warehouse_id' => 'required',
            'payment.amount' => 'required',
        ]);

        // Every managed-stock line must be covered by stock unless overselling is allowed.
        $this->assertSaleStockSufficient($request);

        // Same register discount rules as a direct POS sale (see createPosSale).
        $violation = \App\Services\PosDiscountLimit::violation(
            $request->user('api'),
            (array) $request->input('details', []),
            $request->input('discount', 0),
            $request->input('discount_Method', '2')
        );
        if ($violation) {
            return response()->json(['message' => $violation], 422);
        }

        // A submitted draft is flagged deleted_at (no SoftDeletes trait), so it
        // must be filtered here or a retried / double-clicked submit converts
        // the same draft into a second sale — and charges the card twice.
        $draft = DraftSale::whereNull('deleted_at')->findOrFail($request['draft_sale_id']);
        if ($draft) {

            // A card payment is authorised with Stripe BEFORE the transaction
            // opens, as in CreatePOS: the network round trip never holds stock
            // row locks, and a deadlock retry of the closure can never charge
            // the card a second time. The hold is captured once the sale commits.
            $cardAuthorisations = [];
            $isCardPayment = ($request->payment['payment_method_id'] ?? null) == 1;
            if ($request['amount'] > 0 && $isCardPayment) {
                // Same rule as CreatePOS: a card cannot give change back.
                $overpayment = round((float) $request['amount'] - (float) $request->GrandTotal, 4);
                if ($overpayment > 0) {
                    return response()->json([
                        'message' => 'Total Paid Exceeds Grand Total',
                        'change_return' => $overpayment,
                    ], 422);
                }

                try {
                    $cardAuthorisations = $this->authoriseCardPayments($request, null, 0, [
                        array_merge((array) $request->payment, ['amount' => $request['amount']]),
                    ]);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
                }
            }

            try {
                $sale = \DB::transaction(function () use ($request, $draft, $isCardPayment, $cardAuthorisations) {
                    // Two submits racing past the check above: the row lock lets
                    // only one convert the draft; the other finds it taken and
                    // fails, which releases its card hold below.
                    if (! DraftSale::whereKey($draft->id)->whereNull('deleted_at')->lockForUpdate()->first(['id'])) {
                        throw new \RuntimeException('This draft has already been submitted.');
                    }

                    $helpers = new helpers;
                    $user = Auth::user();
                    // New way: Check user's record_view field (user-level boolean)
                    // Backward compatibility: If record_view is null, fall back to role permission check
                    $order = new Sale;

                    $order->is_pos = 1;
                    $order->date = Carbon::now();
                    $order->time = now()->toTimeString();
                    $order->Ref = app('App\Http\Controllers\SalesController')->getNumberOrder();
                    $order->client_id = $request->client_id;
                    $order->warehouse_id = $request->warehouse_id;
                    $order->tax_rate = $request->tax_rate;
                    $order->TaxNet = $request->TaxNet;
                    $order->discount = $request->discount;
                    // Ensure discount_Method is saved correctly: '1' for percentage, '2' for fixed
                    $order->discount_Method = $request->has('discount_Method') ? (string) $request->discount_Method : '2';
                    $order->shipping = $request->shipping;
                    $order->GrandTotal = $request->GrandTotal;
                    $order->notes = $request->notes;
                    $order->statut = 'completed';
                    $order->payment_statut = 'unpaid';
                    $order->user_id = Auth::user()->id;
                    $order->sales_agent_id = $this->resolvePosSalesAgentId($request);
                    // Multi-Currency snapshot (NULL/NULL = base currency): the sale
                    // inherits the draft's pair unless the request re-picks one.
                    $docCurrency = helpers::resolve_request_currency($request, $draft->currency_id, $draft->exchange_rate);
                    $order->currency_id = $docCurrency['currency_id'];
                    $order->exchange_rate = $docCurrency['exchange_rate'];

                    $order->save();

                    $data = $request['details'];
                    $total_points_earned = 0;
                    foreach ($data as $key => $value) {

                        $product = Product::find($value['product_id']);
                        $unit = Unit::where('id', $value['sale_unit_id'])->first();
                        $total_points_earned += $value['quantity'] * $product->points;

                        // Multi-Pack Selling: a selected pack multiplies the base-unit
                        // stock movement. Defaults to 1 so non-pack lines are unchanged.
                        $packMultiplier = isset($value['pack_multiplier']) && (float) $value['pack_multiplier'] > 0
                            ? (float) $value['pack_multiplier'] : 1;
                        $packQty = $value['quantity'] * $packMultiplier;

                        $baseDate = Carbon::now();
                        $warrantyGuarantee = SaleDetail::computeWarrantyGuaranteeDates($product, $baseDate);

                        $orderDetails[] = array_merge([
                            'date' => Carbon::now(),
                            'sale_id' => $order->id,
                            'sale_unit_id' => $value['sale_unit_id'],
                            'quantity' => $value['quantity'],
                            'product_id' => $value['product_id'],
                            'product_variant_id' => $value['product_variant_id'],
                            'total' => $value['subtotal'],
                            'price' => $value['Unit_price'],
                            'TaxNet' => $value['tax_percent'],
                            'tax_method' => $value['tax_method'],
                            'discount' => $value['discount'],
                            'discount_method' => $value['discount_Method'],
                            'imei_number' => $this->resolveImeiString($value),
                            'price_type' => isset($value['price_type']) ? $value['price_type'] : 'retail',
                            'product_pack_id' => isset($value['product_pack_id']) && $value['product_pack_id'] ? (int) $value['product_pack_id'] : null,
                            'pack_multiplier' => $packMultiplier,
                            'pack_name' => $value['pack_name'] ?? null,
                        ], $warrantyGuarantee);

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
                                ->where('product_id', $value['product_id'])->where('product_variant_id', $value['product_variant_id'])
                                ->lockForUpdate()->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $packQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $packQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } elseif ($unit && app(ComboStockService::class)->moveComponents((int) $value['product_id'], (int) $order->warehouse_id, -ComboStockService::baseQty($packQty, $unit))) {
                            // Virtual combo: its components were taken instead of its own row.
                        } else {
                            $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->lockForUpdate()->first();
                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $packQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $packQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                    SaleDetail::insert($orderDetails);

                    // Serial / IMEI: mark selected serials as sold.
                    $serialService = app(SerialNumberService::class);
                    if ($serialService->isSupported()) {
                        $persistedSerials = SaleDetail::where('sale_id', $order->id)
                            ->orderBy('id', 'asc')
                            ->get();
                        foreach (array_values($data) as $i => $row) {
                            $detail = $persistedSerials->get($i);
                            if (! $detail) {
                                continue;
                            }
                            $serialService->sellOnSale($order, $detail, $row['serial_numbers'] ?? null);
                        }
                    }

                    // Software License products: claim / issue one key per unit sold.
                    \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWrite($order->id);

                    $sale = Sale::findOrFail($order->id);

                    // `amount` can include change to hand back, which must not
                    // count as received (same rule as CreatePOS).
                    $received = (float) $request['amount'];
                    $leftToPay = max((float) $sale->GrandTotal - (float) $sale->paid_amount, 0);
                    $paymentAmount = min($received, $leftToPay);
                    $changeReturn = $received > $leftToPay ? round($received - $leftToPay, 4) : $request['change'];

                    $total_paid = $sale->paid_amount + $paymentAmount;
                    $payment_statut = Sale::paymentStatusFor($sale->GrandTotal, $total_paid);

                    // Any failure here propagates so the whole sale rolls back (the
                    // old catch returned a response from inside the closure, which
                    // committed the sale with its stock already deducted).
                    if ($paymentAmount > 0) {
                        // Customer-wallet tender: debit the wallet, no cash account.
                        $wallets = app(\App\Services\CustomerWallet\CustomerWalletService::class);
                        $fromWallet = $wallets->isWalletMethod($request->payment['payment_method_id'] ?? null);
                        $paymentRef = app('App\Http\Controllers\PaymentSalesController')->getNumberOrder();
                        $walletTxId = null;
                        if ($fromWallet) {
                            $walletTxId = $wallets->payFromWallet((int) $order->client_id, (float) $paymentAmount, 'sale_payment', [
                                'reference_type' => Sale::class,
                                'reference_id' => $order->id,
                                'note' => 'POS sale '.$order->Ref.' · '.$paymentRef,
                                'user_id' => Auth::id(),
                            ])->id;
                        }

                        $paymentSale = PaymentSale::create([
                            'sale_id' => $order->id,
                            'account_id' => (! $fromWallet && $request->payment['account_id']) ? $request->payment['account_id'] : null,
                            'Ref' => $paymentRef,
                            'date' => Carbon::now(),
                            'payment_method_id' => $request->payment['payment_method_id'],
                            'montant' => $paymentAmount,
                            'change' => $fromWallet ? 0 : $changeReturn,
                            'notes' => $request->payment['notes'],
                            'user_id' => Auth::user()->id,
                        ] + \App\Services\CustomerWallet\CustomerWalletService::txColumn($walletTxId));

                        if (! $fromWallet) {
                            Account::adjustBalance($request->payment['account_id'], $paymentAmount);
                        }

                        $sale->update([
                            'paid_amount' => $total_paid,
                            'payment_statut' => $payment_statut,
                        ]);

                        // Card: authorised with Stripe before the transaction opened;
                        // here we only record the result.
                        if ($isCardPayment) {
                            PaymentWithCreditCard::create([
                                'customer_id' => $request->client_id,
                                'payment_id' => $paymentSale->id,
                                'customer_stripe_id' => $cardAuthorisations[0]['customer_stripe_id'] ?? null,
                                'charge_id' => $cardAuthorisations[0]['charge_id'] ?? null,
                            ]);
                        }
                    }

                    // 🪙 Points logic
                    $client = Client::find($request->client_id);
                    $used_points = $request->used_points ?? 0;
                    $discount_from_points = $request->discount_from_points ?? 0;
                    $earned_points = 0;

                    if ($client && ($client->is_royalty_eligible == 1 || $client->is_royalty_eligible || $client->is_royalty_eligible === 1)) {

                        // Deduct used points if valid
                        if ($used_points > 0 && $client->points >= $used_points) {
                            $client->decrement('points', $used_points);
                        }

                        // Earn points
                        $earned_points = $total_points_earned;

                        $client->increment('points', $earned_points);

                        $order_used_points = $used_points;
                        $order_earned_points = $earned_points;
                        $order_discount_from_points = $discount_from_points;
                    } else {
                        $order_used_points = 0;
                        $order_earned_points = 0;
                        $order_discount_from_points = 0;
                    }

                    $order->update([
                        'used_points' => $order_used_points,
                        'earned_points' => $order_earned_points,
                        'discount_from_points' => $order_discount_from_points,
                    ]);

                    $draft->details()->delete();
                    $draft->update([
                        'deleted_at' => Carbon::now(),
                    ]);

                    return $order;

                }, 10);
            } catch (\Throwable $e) {
                // The sale was not written: release the card hold.
                $this->releaseCardAuthorisations($cardAuthorisations);

                if ($e instanceof \App\Services\SupplierWallet\WalletException) {
                    return $e->toResponse();
                }

                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }

            // ============================ END TRANSACTION ============================

            // The sale is committed: take the held card funds.
            $this->captureCardAuthorisations($cardAuthorisations);

            // ---------- AFTER COMMIT: sales-agent commissions (best effort) ----------
            $this->calculatePosCommissions($sale);

            // ---------- AFTER COMMIT: QBO sync (best effort, non-blocking) ----------
            $qboSync = 'skipped';
            try {
                if (class_exists(\App\Jobs\SyncSaleToQuickBooks::class)) {
                    \App\Jobs\SyncSaleToQuickBooks::dispatch($sale->id)->afterCommit();
                    $qboSync = 'queued';
                } else {
                    $sale->load(['saleDetails.product', 'client']);
                    /** @var \App\Services\QuickBooksService $qb */
                    $qb = app(\App\Services\QuickBooksService::class);

                    $res = ! empty($sale->quickbooks_invoice_id)
                        ? $qb->updateInvoice($sale)
                        : $qb->createInvoice($sale);

                    if (! ($res['ok'] ?? false)) {
                        \Log::warning('QBO sync failed (submit_sale_from_draft)', [
                            'sale_id' => $sale->id,
                            'error' => $res['error'] ?? 'unknown',
                            'http' => $res['http'] ?? null,
                            'body' => $res['body'] ?? null,
                        ]);
                        $sale->update([
                            'quickbooks_sync_error' => substr(($res['error'] ?? '').' '.($res['body'] ?? ''), 0, 65000),
                        ]);
                        $qboSync = 'failed';
                    } else {
                        $sale->update([
                            'quickbooks_invoice_id' => $sale->quickbooks_invoice_id ?: ($res['id'] ?? null),
                            'quickbooks_realm_id' => $res['realm'] ?? ($sale->quickbooks_realm_id ?? null),
                            'quickbooks_synced_at' => now(),
                            'quickbooks_sync_error' => null,
                        ]);
                        $qboSync = 'ok';
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('QuickBooks sync failed (non-blocking from draft): '.$e->getMessage(), [
                    'sale_id' => $sale->id,
                    'trace' => $e->getTraceAsString(),
                ]);
                $qboSync = 'failed';
            }

            return response()->json([
                'success' => true,
                'id' => $sale->id,
                'qbo_sync' => $qboSync, // ok | queued | failed | skipped
            ]);

        } else {
            return response()->json(['success' => false], 404);
        }

    }

    // --------------------- data_draft_convert_sale ------------------------\\

    public function data_draft_convert_sale(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name', 'phone']);
        $settings = Setting::where('deleted_at', '=', null)->with('Client')->first();
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);

        $draft_sale_data = DraftSale::with('details.product.unitSale')->where('deleted_at', '=', null)->findOrFail($id);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name', 'address', 'mobile', 'email', 'zip', 'country', 'logo', 'invoice_footer']);

        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name', 'address', 'mobile', 'email', 'zip', 'country', 'logo', 'invoice_footer']);
        }

        if ($draft_sale_data->client_id) {
            $client = Client::where('id', $draft_sale_data->client_id)
                ->whereNull('deleted_at')
                ->first();

            if ($client) {
                $sale['client_id'] = $client->id;
                $client_name = $client->name;

                // ✅ Pass royalty data for frontend usage
                $defaultClient = $client->id;
                $default_client_points = $client->points;
                $default_client_eligible = $client->is_royalty_eligible;
            } else {
                $defaultClient = '';
                $client_name = '';
                $default_client_points = 0;
                $default_client_eligible = false;
            }
        } else {
            $defaultClient = '';
            $client_name = '';
            $default_client_points = 0;
            $default_client_eligible = false;
        }

        if ($draft_sale_data->warehouse_id) {
            if (Warehouse::where('id', $draft_sale_data->warehouse_id)->where('deleted_at', '=', null)->first()) {
                $sale['warehouse_id'] = $draft_sale_data->warehouse_id;
            } else {
                $sale['warehouse_id'] = '';
            }
        } else {
            $sale['warehouse_id'] = '';
        }

        $sale['tax_rate'] = $draft_sale_data->tax_rate;
        $sale['TaxNet'] = $draft_sale_data->TaxNet;
        $sale['discount'] = $draft_sale_data->discount;
        $sale['discount_Method'] = $draft_sale_data->discount_Method ?? '2'; // '1' for percentage, '2' for fixed
        $sale['shipping'] = $draft_sale_data->shipping;
        // Multi-Currency: resume the held order in the currency it was held in
        $sale['currency_id'] = $draft_sale_data->currency_id;
        $sale['exchange_rate'] = $draft_sale_data->exchange_rate;
        $GrandTotal = $draft_sale_data->GrandTotal;

        // Multi-Pack Selling: preload active packs per product so the reloaded
        // POS cart can restore the pack picker. Empty map when feature is off.
        $mpPacksByProduct = [];
        $mpSetting = Setting::current();
        if ((bool) ($mpSetting->enable_multi_pack_selling ?? false)) {
            $mpProductIds = collect($draft_sale_data['details'])->pluck('product_id')->unique()->values()->all();
            if (! empty($mpProductIds)) {
                foreach (ProductPack::whereIn('product_id', $mpProductIds)
                    ->where('is_active', 1)
                    ->whereNull('deleted_at')
                    ->orderBy('is_default', 'desc')
                    ->orderBy('id')
                    ->get() as $pk) {
                    $mpPacksByProduct[$pk->product_id][] = [
                        'id' => $pk->id,
                        'name' => $pk->name,
                        'multiplier' => (float) $pk->multiplier,
                        'price' => (float) $pk->price,
                        'is_default' => (bool) $pk->is_default,
                    ];
                }
            }
        }

        // Combo lines pick serials of their tracked components.
        $serialService = app(SerialNumberService::class);
        $serialComponentsByCombo = $serialService->comboSerialComponents(
            collect($draft_sale_data['details'])->pluck('product_id')->all()
        );

        $detail_id = 0;
        foreach ($draft_sale_data['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                } else {
                    $unit = null;
                }
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $draft_sale_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = $detail->product_variant_id;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $stock = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $stock = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $stock = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('warehouse_id', $draft_sale_data->warehouse_id)
                    ->where('product_variant_id', '=', null)->first();
                // Virtual combo stock: show what the components can still fill.
                app(\App\Services\ComboStockService::class)->overlayRows(array_filter([$item_product]));

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $stock = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $stock = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $stock = 0;
                }

            }

            $data['id'] = $detail->id;
            $data['fix_stock'] = $detail['product']['type'] != 'is_service' ? $stock : '---';
            $data['current'] = $detail['product']['type'] != 'is_service' ? $stock : '---';

            $data['product_type'] = $detail['product']['type'];
            $data['detail_id'] = $detail_id += 1;
            $data['product_id'] = $detail->product_id;
            $data['total'] = $detail->total;
            $data['quantity'] = $detail->quantity;
            $data['qte_copy'] = $detail->quantity;
            $data['etat'] = 'current';
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            $data['sale_unit_id'] = $unit ? $unit->id : '';
            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['serial_components'] = $serialComponentsByCombo[(int) $detail->product_id] ?? [];
            // The draft's picks (kept as imei_number text) regrouped per component.
            $data['serial_groups'] = (object) ($data['serial_components'] && $detail->imei_number
                ? array_intersect_key(
                    $serialService->groupSerialsByProduct($detail->imei_number),
                    array_flip(array_column($data['serial_components'], 'product_id'))
                )
                : []);
            $data['subtotal'] = $detail->total;

            // Multi-Pack Selling: restore the selected pack + available packs.
            $data['product_pack_id'] = $detail->product_pack_id;
            $data['pack_multiplier'] = $detail->pack_multiplier !== null ? (float) $detail->pack_multiplier : 1;
            $data['pack_name'] = $detail->pack_name;
            $data['packs'] = ($detail->product_variant_id === null && isset($mpPacksByProduct[$detail->product_id]))
                ? $mpPacksByProduct[$detail->product_id]
                : [];

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['price_type'] = method_exists($detail, 'getAttribute') && $detail->getAttribute('price_type') ? $detail->price_type : 'retail';

            // Retail / wholesale baselines per sale unit (same rules as
            // get_products_pos) so the reopened cart can switch price types.
            // The held price stays the baseline of the type it was held with.
            $priceVariant = $detail->product_variant_id ? $productsVariants : null;
            $priceUnit = $unit ?: $detail['product']['unitSale'];
            $toSaleUnit = fn ($p) => $priceUnit
                ? ($priceUnit->operator == '/' ? $p / $priceUnit->operator_value : $p * $priceUnit->operator_value)
                : $p;
            $retailBase = $priceVariant ? $priceVariant->price : $detail['product']['price'];
            // Different prices per store: the draft's warehouse prices.
            $draftStoreRow = WarehousePrices::find($detail->product_id, $detail->product_variant_id, $draft_sale_data->warehouse_id);
            $retailBase = WarehousePrices::pick($draftStoreRow, 'price', $retailBase);
            if ($priceVariant && $priceVariant->wholesale > 0) {
                $wholesaleBase = $priceVariant->wholesale;
            } elseif ($detail['product']['wholesale_price'] > 0) {
                $wholesaleBase = $detail['product']['wholesale_price'];
            } else {
                $wholesaleBase = $retailBase;
            }
            $wholesaleBase = WarehousePrices::pick($draftStoreRow, 'wholesale_price', $wholesaleBase);
            $heldWholesale = $data['price_type'] === 'wholesale';
            $data['Unit_price_wholesale'] = $toSaleUnit($wholesaleBase);
            $data['retail_unit_price'] = $heldWholesale ? $toSaleUnit($retailBase) : $detail->price;
            $data['wholesale_unit_price'] = $heldWholesale ? $detail->price : $data['Unit_price_wholesale'];
            $data['min_price'] = WarehousePrices::pick($draftStoreRow, 'min_price', ($priceVariant && $priceVariant->min_price > 0)
                ? $priceVariant->min_price
                : ($detail['product']['min_price'] ?? 0));

            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
                $data['Total_price'] = $data['Net_price'] + $data['taxe'];
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                $data['Total_price'] = $data['Net_price'] + $data['taxe'];
            }

            $details[] = $data;
        }

        $categories = Category::where('deleted_at', '=', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', '=', null)->get();
        $stripe_key = \App\Models\PaymentSetting::current()->stripe_key;
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name', 'is_cash']);

        return response()->json([
            'stripe_key' => $stripe_key,
            'brands' => $brands,
            'warehouse_id' => $sale['warehouse_id'],
            'client_id' => $sale['client_id'],
            'client_name' => $client_name,
            'clients' => $clients,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
            'sale' => $sale,
            'GrandTotal' => $GrandTotal,
            'details' => $details,
            'defaultClient' => $defaultClient,
            'default_client_points' => $default_client_points,
            'default_client_eligible' => $default_client_eligible,
            'point_to_amount_rate' => $settings->point_to_amount_rate,
        ]);
    }

    // ------------ Get Products (POS) --------------\\
    //
    // NOTE:
    // Historically this endpoint implemented server‑side pagination
    // (offset/limit based on PosSetting::products_per_page and a `page`
    // query parameter). The POS UI has since been refactored to use a
    // single API call that loads the full filtered list for a warehouse
    // and performs pagination purely on the frontend.
    //
    // To support that, this method now always returns the complete
    // filtered collection for the given warehouse (no offset/limit),
    // while preserving the existing response shape:
    //   { products: [...], totalRows: <int> }
    // `totalRows` is the total number of matching products.

    public function GetProductsByParametre(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        // The POS grid is loaded for one warehouse: it must be assigned to the
        // cashier, otherwise the request reads another warehouse's stock. With
        // no warehouse picked yet the query returns nothing, as it always did.
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
        }

        $data = [];

        // Allow Overselling (global Features switch): when ON, products with a
        // zero or negative stock must still appear in the POS grid / search —
        // otherwise the cashier can never reach them even though selling them
        // is permitted.
        $mpSetting = Setting::current();
        $allow_overselling = (bool) ($mpSetting->allow_overselling ?? false);
        // Virtual combo stock: a combo's own row stays at 0, so combos skip the
        // SQL stock filter and are filtered on their component availability below.
        $virtualCombos = ComboStockService::enabled();
        $stockFiltered = $request->stock == '1' && $request->product_service == '1' && ! $allow_overselling;

        $product_warehouse_query = product_warehouse::where('warehouse_id', $request->warehouse_id)
            ->with('product', 'product.unitSale')
            // Products belong to a warehouse (Settings -> Features): drop the
            // zero-stock rows of products owned by another branch.
            ->whereHas('product', fn ($p) => $this->scopeProductsToWarehouses($p))
            ->where('deleted_at', '=', null)
            ->withoutVariantParentRows()
            ->where(function ($query) use ($request, $allow_overselling, $virtualCombos) {
                return $query->whereHas('product', function ($q) {
                    $q->where('not_selling', '=', 0);
                })
                    ->where(function ($query) use ($request) {
                        return $query->whereHas('product', function ($q) use ($request) {
                            if (isset($request->product_combo) && $request->product_combo == '1') {
                                $q->whereIn('type', ['is_combo', 'is_single', 'is_variant', 'is_service']);
                            } elseif (! isset($request->product_combo) || $request->product_combo == '0') {
                                $q->whereNotIn('type', ['is_combo']);
                            }
                        });
                    })
                    ->where(function ($query) use ($request, $allow_overselling, $virtualCombos) {
                        if ($request->stock == '1' && $request->product_service == '1') {
                            // Overselling ON → drop the stock filter so
                            // zero / negative rows stay in the grid.
                            return $allow_overselling
                                ? $query
                                : $query->where('qte', '>', 0)->orWhere('manage_stock', false)
                                    // "Always in stock" products stay in the grid at zero stock.
                                    ->orWhereHas('product', fn ($p) => $p->where('always_in_stock', 1))
                                    ->when($virtualCombos, fn ($q) => $q->orWhereHas('product', fn ($p) => $p->where('type', 'is_combo')));

                        } elseif ($request->stock == '1' && $request->product_service == '0') {
                            // Overselling ON → stock-managed products only, no qte filter.
                            return $allow_overselling
                                ? $query->where('manage_stock', true)
                                : $query->where('qte', '>', 0)->orWhere('manage_stock', true)
                                    ->orWhereHas('product', fn ($p) => $p->where('always_in_stock', 1));

                        } else {
                            return $query->where('manage_stock', true);
                        }
                    });
            })

        // Filter
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('category_id'), function ($query) use ($request) {
                    return $query->whereHas('product', function ($q) use ($request) {
                        $q->where('category_id', '=', $request->category_id);
                    });
                });
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('brand_id'), function ($query) use ($request) {
                    return $query->whereHas('product', function ($q) use ($request) {
                        $q->where('brand_id', '=', $request->brand_id);
                    });
                });
            })
            ->orderBy('product_id', 'desc');

        // The POS keeps the whole warehouse catalogue client-side (offline
        // selling, local barcode lookup), so the row count is taken from the
        // rows actually returned (see the response) — no separate COUNT query.
        $product_warehouse_data = $product_warehouse_query->get();

        if ($virtualCombos) {
            app(ComboStockService::class)->overlayRows($product_warehouse_data);
            if ($stockFiltered) {
                $product_warehouse_data = $product_warehouse_data->reject(fn ($pw) => optional($pw->product)->type === 'is_combo'
                    && $pw->qte <= 0
                    && ! $pw->combo_unlimited
                    && ! $pw->product->always_in_stock
                )->values();
            }
        }

        // Every variant row's variant in one query instead of one per row.
        $variantIds = $product_warehouse_data->pluck('product_variant_id')->filter()->unique()->values()->all();
        $variantsById = empty($variantIds) ? collect() : ProductVariant::whereIn('id', $variantIds)
            ->where('deleted_at', null)
            ->get()
            ->keyBy('id');

        // Combo tiles carry their tracked components for the serial pickers.
        $serialComponentsByCombo = app(SerialNumberService::class)->comboSerialComponents(
            $product_warehouse_data->filter(fn ($pw) => optional($pw->product)->type === 'is_combo')->pluck('product_id')->all()
        );

        // Multi-Pack Selling: preload active packs for single products (one query)
        // when the feature is enabled. Empty map otherwise, so POS is unchanged.
        $packsByProduct = [];
        if ((bool) ($mpSetting->enable_multi_pack_selling ?? false)) {
            $productIds = $product_warehouse_data->pluck('product_id')->unique()->values()->all();
            if (! empty($productIds)) {
                $packRows = ProductPack::whereIn('product_id', $productIds)
                    ->where('is_active', 1)
                    ->whereNull('deleted_at')
                    ->orderBy('is_default', 'desc')
                    ->orderBy('id')
                    ->get();
                foreach ($packRows as $pr) {
                    $packsByProduct[$pr->product_id][] = [
                        'id' => $pr->id,
                        'name' => $pr->name,
                        'multiplier' => (float) $pr->multiplier,
                        'price' => (float) $pr->price,
                        'is_default' => (bool) $pr->is_default,
                    ];
                }
            }
        }

        // Additional barcodes (manufacturer EANs …) for the POS's local scan lookup.
        $barcodeMap = \App\Services\ProductBarcodes::map($product_warehouse_data->pluck('product_id')->all());

        // Different prices per store: this POS warehouse's own prices, one query.
        $storePriceMap = WarehousePrices::map($request->warehouse_id);

        $num = fn ($v) => $v === null ? null : (float) $v;

        foreach ($product_warehouse_data as $product_warehouse) {
            // Plain reads (see ProductsController::Products_by_Warehouse): each
            // Eloquent attribute read costs ~10 µs here and this loop made ~80 per
            // product, so a 21,000-product POS took ~20 s to load its grid.
            // Raw values, cast exactly as the Product model casts them.
            $a = $product_warehouse['product']->getAttributes();
            $prodRow = (object) [
                'code' => $a['code'] ?? null,
                'name' => $a['name'] ?? null,
                'type' => $a['type'] ?? null,
                'image' => $a['image'] ?? null,
                'scale_plu' => $a['scale_plu'] ?? null,
                'not_selling' => $a['not_selling'] ?? null,
                'is_imei' => isset($a['is_imei']) ? (int) $a['is_imei'] : null,
                'is_batch_tracked' => isset($a['is_batch_tracked']) ? (bool) $a['is_batch_tracked'] : null,
                'hide_from_online_store' => isset($a['hide_from_online_store']) ? (bool) $a['hide_from_online_store'] : null,
                'always_in_stock' => isset($a['always_in_stock']) ? (bool) $a['always_in_stock'] : null,
                'price' => $num($a['price'] ?? null),
                'wholesale_price' => $num($a['wholesale_price'] ?? null),
                'min_price' => $num($a['min_price'] ?? null),
                'discount' => $num($a['discount'] ?? null),
                'discount_method' => $a['discount_method'] ?? null,
                'TaxNet' => $num($a['TaxNet'] ?? null),
                'tax_method' => $a['tax_method'] ?? null,
            ];
            $unitSale = $product_warehouse['product']->unitSale;
            $pwRaw = $product_warehouse->getAttributes();
            $pwQte = $num($pwRaw['qte'] ?? null);
            $pwProductId = isset($pwRaw['product_id']) ? (int) $pwRaw['product_id'] : null;
            $pwVariantId = $pwRaw['product_variant_id'] ?? null;
            $item['barcodes'] = $barcodeMap[$pwProductId.'|'.($pwVariantId ?? '')] ?? [];
            if ($pwVariantId) {
                $productsVariants = $variantsById->get($pwVariantId);

                // A deleted variant (or one pointing at another product) is not
                // sellable; this used to fatal on the null and fail the whole grid.
                if (! $productsVariants || $productsVariants->product_id != $pwProductId) {
                    continue;
                }

                $item['product_variant_id'] = $pwVariantId;
                $item['Variant'] = '['.$productsVariants->name.']'.$prodRow->name;
                $item['name'] = '['.$productsVariants->name.']'.$prodRow->name;

                $item['code'] = $productsVariants->code;
                $item['barcode'] = $productsVariants->code;

                $product_price = $productsVariants->price;

            } else {
                $item['product_variant_id'] = null;
                $item['Variant'] = null;
                $item['code'] = $prodRow->code;
                $item['name'] = $prodRow->name;
                $item['barcode'] = $prodRow->code;

                $product_price = $prodRow->price;

            }
            $storeRow = $storePriceMap[WarehousePrices::key($pwProductId, $pwVariantId)] ?? null;
            $product_price = WarehousePrices::pick($storeRow, 'price', $product_price);
            $item['id'] = $pwProductId;
            $firstimage = explode(',', $prodRow->image);
            $item['image'] = $firstimage[0];

            // Common product flags & meta
            $item['product_type'] = $prodRow->type;
            // Weighing-scale item code (standard products only).
            $item['scale_plu'] = ! $pwVariantId && $prodRow->type === 'is_single'
                ? ($prodRow->scale_plu ?? null) : null;
            $item['is_imei'] = $prodRow->is_imei;
            $item['serial_components'] = $serialComponentsByCombo[(int) $pwProductId] ?? [];
            $item['is_batch_tracked'] = (bool) ($prodRow->is_batch_tracked ?? false);
            $item['not_selling'] = $prodRow->not_selling;
            $item['hide_from_online_store'] = $prodRow->hide_from_online_store ?? 0;
            // Product-level overselling flag (sellable at zero stock).
            $item['always_in_stock'] = (bool) ($prodRow->always_in_stock ?? false)
                || (bool) ($pwRaw['combo_unlimited'] ?? null);

            // Multi-Pack Selling: only single products (null variant) carry packs.
            $item['packs'] = ($item['product_variant_id'] === null && isset($packsByProduct[$pwProductId]))
                ? $packsByProduct[$pwProductId]
                : [];

            // Persist tax/discount metadata so the POS can work without per‑click calls
            $item['tax_method'] = $prodRow->tax_method;
            $item['tax_percent'] = $prodRow->TaxNet;
            $item['discount_method'] = $prodRow->discount_method;
            // Legacy camel-cased name used in some existing JS
            $item['discount_Method'] = $prodRow->discount_method;
            $item['discount'] = $prodRow->discount;

            if ($unitSale) {

                if ($unitSale->operator == '/') {
                    $item['qte_sale'] = $pwQte * $unitSale->operator_value;
                    $price = $product_price / $unitSale->operator_value;

                } else {
                    $item['qte_sale'] = $pwQte / $unitSale->operator_value;
                    $price = $product_price * $unitSale->operator_value;

                }

            } else {
                $item['qte_sale'] = $prodRow->type != 'is_service' ? $pwQte : '---';
                $price = $product_price;
            }

            $item['unitSale'] = $unitSale ? $unitSale->ShortName : '';
            $item['sale_unit_id'] = $unitSale ? $unitSale->id : null;
            $item['qte'] = $prodRow->type != 'is_service' ? $pwQte : '---';

            // Base (retail) price per sale unit and original/base price (fix_price)
            $item['Unit_price'] = $price;
            $item['fix_price'] = $product_price;

            // --- Compute wholesale price per sale unit (min_price is returned raw from DB) ---
            // For variant products, prefer variant-level wholesale/min prices when available.
            $baseWholesale = null;
            $baseMinPrice = null;

            if ($pwVariantId && isset($productsVariants)) {
                // Variant wholesale column is `wholesale` (NOT wholesale_price)
                if (isset($productsVariants->wholesale) && $productsVariants->wholesale > 0) {
                    $baseWholesale = $productsVariants->wholesale;
                }
                if (isset($productsVariants->min_price) && $productsVariants->min_price > 0) {
                    $baseMinPrice = $productsVariants->min_price;
                }
            }

            // Fallbacks for non-variant products or when variant values are empty/zero
            if ($baseWholesale === null || $baseWholesale <= 0) {
                if (isset($prodRow->wholesale_price) && $prodRow->wholesale_price > 0) {
                    $baseWholesale = $prodRow->wholesale_price;
                } else {
                    // If no explicit wholesale is configured, use the regular product price
                    $baseWholesale = $product_price;
                }
            }

            if ($baseMinPrice === null || $baseMinPrice <= 0) {
                $baseMinPrice = $prodRow->min_price ?? 0;
            }

            // Different prices per store: wholesale / minimum of this warehouse.
            $baseWholesale = WarehousePrices::pick($storeRow, 'wholesale_price', $baseWholesale);
            $baseMinPrice = WarehousePrices::pick($storeRow, 'min_price', $baseMinPrice);

            $wholesale_product_price = $baseWholesale;

            if ($unitSale) {
                if ($unitSale->operator == '/') {
                    $wholesale_unit_price = $wholesale_product_price / $unitSale->operator_value;
                } else {
                    $wholesale_unit_price = $wholesale_product_price * $unitSale->operator_value;
                }
            } else {
                $wholesale_unit_price = $wholesale_product_price;
            }

            // ---------------- Discount for base (retail) price ----------------
            if ($prodRow->discount !== 0.0) {
                // percent %
                if ($prodRow->discount_method == '1') {
                    $discount_price = $price * $prodRow->discount / 100;
                    $price_discounted = $price - $discount_price;
                } else {
                    // fixed
                    $discount_price = $prodRow->discount;
                    $price_discounted = $price - $prodRow->discount;
                }
            } else {
                $discount_price = 0;
                $price_discounted = $price;
            }

            // Expose effective discount amount and meta so frontend can work without extra calls
            $item['DiscountNet'] = $discount_price;

            // ---------------- Tax & Net/Total price (base / retail) ----------------
            if ($prodRow->TaxNet !== 0.0) {
                // Exclusive
                if ($prodRow->tax_method == '1') {
                    $tax_price = $price_discounted * $prodRow->TaxNet / 100;
                    $item['Net_price'] = $price_discounted;
                    $item['tax_price'] = $tax_price;
                    $item['Total_price'] = $price_discounted + $tax_price;

                    // Inclusive
                } else {
                    $tax_price = $price_discounted * $prodRow->TaxNet / 100;
                    $item['Total_price'] = $price_discounted;
                    $item['Net_price'] = $price_discounted - $tax_price;
                    $item['tax_price'] = $tax_price;
                }
            } else {
                $tax_price = 0;
                $item['Net_price'] = $price_discounted;
                $item['Total_price'] = $price_discounted;
                $item['tax_price'] = 0;
            }

            // ---------------- Wholesale discount/tax/net ----------------
            if ($prodRow->discount !== 0.0) {
                if ($prodRow->discount_method == '1') {
                    $wholesale_discount = $wholesale_unit_price * $prodRow->discount / 100;
                    $wholesale_price_discounted = $wholesale_unit_price - $wholesale_discount;
                } else {
                    $wholesale_discount = $prodRow->discount;
                    $wholesale_price_discounted = $wholesale_unit_price - $wholesale_discount;
                }
            } else {
                $wholesale_discount = 0;
                $wholesale_price_discounted = $wholesale_unit_price;
            }

            if ($prodRow->TaxNet !== 0.0) {
                if ($prodRow->tax_method == '1') {
                    $wholesale_tax_price = $wholesale_price_discounted * $prodRow->TaxNet / 100;
                    $wholesale_net_price = $wholesale_price_discounted + $wholesale_tax_price;
                } else {
                    $wholesale_tax_price = $wholesale_price_discounted * $prodRow->TaxNet / 100;
                    $wholesale_net_price = $wholesale_price_discounted;
                }
            } else {
                $wholesale_tax_price = 0;
                $wholesale_net_price = $wholesale_price_discounted;
            }

            $item['Unit_price_wholesale'] = $wholesale_unit_price;
            $item['wholesale_Net_price'] = $wholesale_net_price;
            // Return raw min price from DB without discount/tax/unit conversion
            $item['min_price'] = $baseMinPrice;

            $data[] = $item;
        }

        return response()->json([
            'products' => $data,
            'totalRows' => count($data),
            'server_time' => now()->toIso8601String(),
            'favorites' => $this->posFavoriteKeys($request->user('api')->id),
        ]);
    }

    // ---------------- POS favourite products (per cashier) ----------------\\

    /** pos_favorite_products exists (its migration may not have run yet); checked once per request. */
    protected function posFavoritesSupported(): bool
    {
        static $supported = null;

        if ($supported === null) {
            try {
                $supported = \Schema::hasTable('pos_favorite_products');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }

        return $supported;
    }

    /** The cashier's favourites as "productId:variantId" keys (0 = no variant). */
    protected function posFavoriteKeys($userId)
    {
        // Never let a missing table (migration not run yet) break the grid —
        // and do not run a query known to fail on every grid load.
        if (! $this->posFavoritesSupported()) {
            return [];
        }
        try {
            return \DB::table('pos_favorite_products')
                ->where('user_id', $userId)
                ->get(['product_id', 'product_variant_id'])
                ->map(fn ($f) => $f->product_id.':'.$f->product_variant_id)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function toggleFavorite(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'product_id' => 'required|integer',
            'product_variant_id' => 'nullable|integer',
        ]);

        if (! $this->posFavoritesSupported()) {
            return response()->json([
                'message' => 'Favourites are not available until the database update has been run.',
            ], 409);
        }

        $userId = $request->user('api')->id;
        $key = [
            'user_id' => $userId,
            'product_id' => (int) $request->product_id,
            'product_variant_id' => (int) ($request->product_variant_id ?? 0),
        ];

        $existing = \DB::table('pos_favorite_products')->where($key);
        if ($existing->exists()) {
            $existing->delete();
            $favorite = false;
        } else {
            \DB::table('pos_favorite_products')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);
            $favorite = true;
        }

        return response()->json([
            'favorite' => $favorite,
            'favorites' => $this->posFavoriteKeys($userId),
        ]);
    }

    // --------------------- Delta: products stock changes -----------\\
    // Returns only the product_warehouse rows whose updated_at is newer
    // than the provided `since` timestamp. Used by the POS UI to keep
    // stock fresh across multiple cashiers without re-downloading the
    // full product list after every sale.
    public function GetProductsChanges(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $warehouseId = $request->warehouse_id;
        $since = $request->since;
        $serverTime = now()->toIso8601String();

        if (!$warehouseId) {
            return response()->json([
                'products' => [],
                'server_time' => $serverTime,
            ]);
        }

        // Stock deltas are warehouse-scoped data — same rule as the POS grid.
        $this->abortIfWarehouseDenied($warehouseId);

        $query = product_warehouse::where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->with('product.unitSale');

        if (!empty($since)) {
            try {
                $query->where('updated_at', '>', $since);
            } catch (\Throwable $e) {
                // Ignore malformed since; fall through to full warehouse read.
            }
        }

        $rows = $this->withVirtualComboRows($query->get(), $warehouseId);

        $data = [];
        foreach ($rows as $pw) {
            $formatted = $this->formatStockRow($pw);
            if ($formatted !== null) {
                $data[] = $formatted;
            }
        }

        return response()->json([
            'products' => $data,
            'server_time' => $serverTime,
        ]);
    }

    // Virtual combo stock: a stock change on a combo or a component changes
    // every combo sharing those components, and none of their own rows moved.
    // Adds the affected rows (the sold combo's components and every combo
    // built from a changed product) and overlays the combo availability.
    private function withVirtualComboRows($rows, $warehouseId)
    {
        if (! ComboStockService::enabled() || $rows->isEmpty()) {
            return $rows;
        }

        $comboStock = app(ComboStockService::class);
        $productIds = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $components = [];
        foreach ($comboStock->recipes($productIds) as $recipe) {
            $components = array_merge($components, array_keys($recipe));
        }
        $affected = array_merge($components, $comboStock->combosUsing(array_merge($productIds, $components)));
        $missing = array_values(array_diff(array_unique($affected), $productIds));

        if ($missing) {
            $rows = $rows->concat(product_warehouse::where('warehouse_id', $warehouseId)
                ->whereNull('deleted_at')
                ->whereNull('product_variant_id')
                ->whereIn('product_id', $missing)
                ->with('product.unitSale')
                ->get());
        }
        $comboStock->overlayRows($rows);

        return $rows;
    }

    // Shared helper: format a product_warehouse row for stock-only responses.
    // Mirrors the qte / qte_sale computation used by GetProductsByParametre.
    private function formatStockRow($pw)
    {
        if (!$pw) return null;
        $product = $pw->product;
        if (!$product) return null;

        $isService = $product->type === 'is_service';
        $qte = $isService ? '---' : $pw->qte;

        if ($product->unitSale) {
            if ($product->unitSale->operator == '/') {
                $qteSale = $pw->qte * $product->unitSale->operator_value;
            } else {
                $qteSale = $pw->qte / $product->unitSale->operator_value;
            }
        } else {
            $qteSale = $isService ? '---' : $pw->qte;
        }

        return [
            'id' => $pw->product_id,
            'product_variant_id' => $pw->product_variant_id,
            'qte' => $qte,
            'qte_sale' => $qteSale,
        ];
    }

    // --------------------- Get Element POS ------------------------\\

    public function GetELementPos(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name', 'phone']);
        $settings = Setting::where('deleted_at', '=', null)->with('Client')->first();
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name', 'address', 'mobile', 'email', 'zip', 'country', 'logo', 'invoice_footer']);

            if ($settings->warehouse_id) {
                if (Warehouse::where('id', $settings->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $defaultWarehouse = $settings->warehouse_id;
                } else {
                    $defaultWarehouse = '';
                }
            } else {
                $defaultWarehouse = '';
            }

        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name', 'address', 'mobile', 'email', 'zip', 'country', 'logo', 'invoice_footer']);

            if ($settings->warehouse_id) {
                if (Warehouse::where('id', $settings->warehouse_id)->whereIn('id', $warehouses_id)->where('deleted_at', '=', null)->first()) {
                    $defaultWarehouse = $settings->warehouse_id;
                } else {
                    $defaultWarehouse = '';
                }
            } else {
                $defaultWarehouse = '';
            }
        }

        if ($settings->client_id) {
            $client = Client::where('id', $settings->client_id)
                ->whereNull('deleted_at')
                ->first();

            if ($client) {
                $defaultClient = $client->id;
                $default_client_name = $client->name;
                $default_client_points = $client->points;
                $default_client_eligible = $client->is_royalty_eligible; // ✅ New
            } else {
                $defaultClient = '';
                $default_client_name = '';
                $default_client_points = 0;
                $default_client_eligible = false;
            }
        } else {
            $defaultClient = '';
            $default_client_name = '';
            $default_client_points = 0;
            $default_client_eligible = false;
        }

        $categories = Category::where('deleted_at', '=', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', '=', null)->get();
        $stripe_key = \App\Models\PaymentSetting::current()->stripe_key;
        $pos_setting = PosSetting::where('deleted_at', '=', null)->first();

        // Allow Overselling lives on the global settings row (Settings > Features),
        // not on pos_settings — mirror the real value onto the payload the POS
        // reads, exactly like SettingsController@get_pos_Settings does, otherwise
        // the POS would keep blocking out-of-stock products.
        if ($pos_setting) {
            $pos_setting->allow_overselling = (bool) ($settings->allow_overselling ?? false);
        }

        // Change Salesperson During Checkout (Settings → Features): when on,
        // ship the active-user list so the POS can attribute the sale to the
        // right employee without switching the logged-in session.
        $salesperson_switch_enabled = (bool) ($settings->enable_pos_salesperson_switch ?? false);
        $salespeople = $salesperson_switch_enabled
            ? User::where('deleted_at', '=', null)
                ->where('statut', 1)
                ->orderBy('username')
                ->get(['id', 'username', 'firstname', 'lastname'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => trim(($u->firstname ?? '').' '.($u->lastname ?? '')) ?: $u->username,
                    'username' => $u->username,
                ])
                ->values()
            : collect();
        // Commissions: active sales agents for the POS agent picker (same
        // choice as the Sales Agent field on Create Sale).
        $sales_agents = SalesAgent::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'user_id']);
        $products_per_page = $pos_setting ? $pos_setting->products_per_page : 12;
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name', 'is_cash']);
        $languages_available = Language::where('is_active', true)->get(['name', 'locale', 'flag']);

        return response()->json([
            // Company / receipt header info (used for POS receipt + offline printing fallback)
            'setting' => [
                // Resolved to an existing file (or the default logo) so the
                // cached offline receipt never prints a broken-image box.
                'logo' => $settings ? receipt_logo_url($settings->logo) : null,
                'CompanyName' => $settings ? $settings->CompanyName : null,
                'CompanyAdress' => $settings ? $settings->CompanyAdress : null,
                'email' => $settings ? $settings->email : null,
                'CompanyPhone' => $settings ? $settings->CompanyPhone : null,
                'vat_number' => $settings ? ($settings->vat_number ?? null) : null,
                'company_name_ar' => $settings ? ($settings->company_name_ar ?? null) : null,
                'zatca_enabled' => $settings ? (bool) ($settings->zatca_enabled ?? false) : false,
                // Preferred invoice format for POS printing
                'invoice_format' => $settings && in_array($settings->invoice_format, ['thermal', 'a4'], true)
                    ? $settings->invoice_format
                    : 'thermal',
                // Offline sync toggle: when false, POS requires online and disables offline queueing
                'offline_sync_enabled' => $settings ? (bool) ($settings->offline_sync_enabled ?? true) : true,
                // Warehouse Letterhead: lets the POS rebuild the issuing branch's
                // header for offline receipts (the warehouse rows below carry it).
                'enable_warehouse_letterhead' => \App\Services\InvoiceIssuerService::enabled($settings),
            ],
            'stripe_key' => $stripe_key,
            'brands' => $brands,
            'defaultWarehouse' => $defaultWarehouse,
            'defaultClient' => $defaultClient,
            'default_client_name' => $default_client_name,
            'clients' => $clients,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
            'languages_available' => $languages_available,
            'products_per_page' => $products_per_page,
            'default_client_points' => $default_client_points,
            'default_client_eligible' => $default_client_eligible,
            'point_to_amount_rate' => $settings->point_to_amount_rate,
            'default_tax' => $settings->default_tax ?? 0,
            'default_account_id' => $settings->default_account_id ?? null,
            'default_payment_method_id' => $settings->default_payment_method_id ?? null,
            'pos_settings' => $pos_setting,
            'enable_pos_salesperson_switch' => $salesperson_switch_enabled,
            'salespeople' => $salespeople,
            'sales_agents' => $sales_agents,
        ]);
    }

    // ------------- Reference Number Draft -----------\\

    public function getNumberOrderDraft()
    {

        $last = DB::table('draft_sales')->latest('id')->first();

        if ($last) {
            $item = (string) ($last->Ref ?? '');
            // Accept DR_1111 or DR-1111 (and similar) without assuming array indexes.
            if (preg_match('/^([A-Za-z]+)[_-]?(\d+)$/', $item, $m)) {
                $prefix = $m[1];
                $inMsg = ((int) $m[2]) + 1;
                $code = $prefix.'_'.$inMsg;
            } else {
                $code = 'DR_1111';
            }
        } else {
            $code = 'DR_1111';
        }

        return $code;
    }

    /**
     * Multi-Pack Selling stock guard.
     *
     * A pack line removes `quantity × pack_multiplier` base units (then the unit
     * operator) from stock. If overselling is disabled, reject any pack line
     * (pack_multiplier > 1) that would drive a managed-stock product negative,
     * so a "pack of 4" can't be sold when only 1 piece is on hand. Non-pack
     * lines are untouched (the existing frontend guard already covers them).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    /**
     * Timestamp an offline-queued POS sale should be booked at.
     *
     * The POS sends `offline_created_at` (ISO-8601, the moment the sale was
     * rung up on the till) when replaying its offline queue. It is honoured
     * only together with a sale_uuid (i.e. a genuine queued sale), only when
     * it parses, and never in the future or older than 31 days — anything
     * else falls back to the request time exactly as before.
     */
    protected function resolveOfflineSaleTimestamp(Request $request, $saleUuid): Carbon
    {
        $now = Carbon::now();
        $raw = $request->input('offline_created_at');
        if (empty($saleUuid) || ! is_string($raw) || trim($raw) === '') {
            return $now;
        }
        try {
            $at = Carbon::parse($raw)->setTimezone($now->getTimezone());
        } catch (\Throwable $e) {
            return $now;
        }
        // Allow a minute of clock skew; otherwise never post-date a sale.
        if ($at->greaterThan($now->copy()->addMinute()) || $at->lessThan($now->copy()->subDays(31))) {
            return $now;
        }

        return $at->greaterThan($now) ? $now : $at;
    }
}
