<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaymentLink;
use App\Models\Central\TenantBillingPayment;
use App\Services\Billing\BillingPaymentVerifier;
use App\Services\Billing\PaymentLinkNotifier;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Support\IsoCurrencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public payment page for a super-admin payment link: /pay/{token}.
 *
 * The 40-char random token is the only credential, so nothing here trusts
 * anything else from the request: attempts are looked up through the link,
 * and a gateway return is confirmed against the reference stored at checkout.
 */
class PaymentLinkCheckoutController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $link = $this->findLink($token);
        $link->loadMissing('tenant');

        $attempt = $request->query('payment')
            ? $link->attempts()->find((int) $request->query('payment'))
            : null;

        $settings = GeneralSetting::instance();

        $state = match (true) {
            $link->isPaid()                    => 'paid',
            $link->status === PaymentLink::STATUS_REFUNDED => 'refunded',
            $link->status === PaymentLink::STATUS_CANCELLED => 'cancelled',
            $link->isExpired()                 => 'expired',
            $link->pendingOfflineAttempt() !== null => 'review',
            // Back from a webhook-only gateway (PayFast, WaafiPay) — wait for its webhook.
            $attempt && $attempt->isPending() && ! BillingPaymentVerifier::canVerify((string) $attempt->gateway)
                && $attempt->gateway !== 'offline' => 'processing',
            default                            => 'pay',
        };

        return view('central.pay-link', [
            'link'           => $link,
            'state'          => $state,
            'attempt'        => $attempt,
            'amountLabel'    => PaymentLinkNotifier::formatAmount($link),
            'gateways'       => $state === 'pay' ? PaymentGatewayFactory::getAvailableGateways() : [],
            'bankDetails'    => $settings->getBankDetails(),
            'appName'        => $settings->app_name ?: config('app.name', 'Stocky'),
            'currencySymbol' => strtoupper($link->currency) === strtoupper(GeneralSetting::currencyCode())
                ? GeneralSetting::currencySymbol()
                : '',
        ]);
    }

    public function process(Request $request, string $token): RedirectResponse
    {
        $link = $this->findLink($token);

        if (! $link->isPayable() || $link->pendingOfflineAttempt()) {
            return redirect()->route('central.pay-link.show', ['token' => $token]);
        }

        $available = array_keys(PaymentGatewayFactory::getAvailableGateways());

        $rules = ['gateway' => ['required', 'string', 'in:' . implode(',', $available ?: ['none'])]];
        if ($request->input('gateway') === 'offline') {
            $rules['payment_proof'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }
        $request->validate($rules);

        $gatewayKey = $request->input('gateway');
        $gateway    = PaymentGatewayFactory::resolve($gatewayKey);
        if (! $gateway) {
            return back()->withErrors(['gateway' => __('central.PayLinkGatewayUnavailable')]);
        }

        $tenant = $link->tenant;
        if (! $tenant) {
            abort(404);
        }

        // A new attempt replaces any abandoned one, so a half-finished checkout
        // never sits pending on the tenant's billing.
        $link->attempts()
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->where('gateway', '!=', 'offline')
            ->get()
            ->each(fn (TenantBillingPayment $old) => $old->markSuperseded());

        $base = [
            'tenant_id'              => $link->tenant_id,
            'tenant_subscription_id' => null,
            'plan_id'                => null,
            'payment_link_id'        => $link->id,
            'amount'                 => $link->amount,
            'tax'                    => 0,
            'currency'               => $link->currency,
            // Hyphen, not underscore: the tenant SPA shows billing_cycle capitalised as-is.
            'billing_cycle'          => 'one-time',
            'gateway'                => $gatewayKey,
            'status'                 => TenantBillingPayment::STATUS_PENDING,
            'notes'                  => 'Payment link: ' . $link->title,
            'metadata'               => [
                'source'               => 'payment_link',
                'payment_link_id'      => $link->id,
                'email'                => $tenant->admin_email,
                'checkout_name'        => Str::limit($link->title, 120, ''),
                'checkout_description' => Str::limit($link->description ?: $link->title, 200, ''),
            ],
        ];

        // ── Bank transfer: keep the proof, wait for an admin to approve ──
        if ($gatewayKey === 'offline') {
            try {
                $uploadDir = upload_public_path('payment-proofs');
                if (! File::isDirectory($uploadDir)) {
                    File::makeDirectory($uploadDir, 0755, true);
                }
                $file     = $request->file('payment_proof');
                // Extension from the sniffed MIME type, never the client's name —
                // the file lands under public/.
                $filename = 'proof_' . $tenant->id . '_link' . $link->id . '_' . time() . '.' . \App\Support\SafeUpload::contentExtension($file, ['jpg', 'png', 'webp', 'pdf'], 'payment_proof');
                $file->move($uploadDir, $filename);

                $proofPayment = TenantBillingPayment::create(array_merge($base, [
                    'gateway_currency'   => $link->currency,
                    'gateway_amount'     => $link->amount,
                    'exchange_rate'      => 1,
                    'conversion_applied' => false,
                    'gateway_payment_id' => 'offline_' . uniqid(),
                    'payment_proof_path' => upload_path('payment-proofs') . '/' . $filename,
                ]));

                // The customer sees the status on the link page; alert the admin.
                \App\Services\EmailNotificationService::adminPaymentProof(
                    (string) ($tenant->admin_email ?? ''),
                    $tenant->company_name ?? $tenant->id,
                    $link->amount,
                    $link->title,
                    'super/payments/' . $proofPayment->id
                );

                return redirect()->route('central.pay-link.show', ['token' => $token]);
            } catch (\Throwable $e) {
                Log::error("PaymentLink {$link->id}: offline proof upload failed — {$e->getMessage()}", ['exception' => $e]);

                return back()->withErrors(['payment' => __('central.PayLinkSubmitFailed')]);
            }
        }

        // ── Online gateway ──
        if (! IsoCurrencies::isValid($link->currency)) {
            Log::error("PaymentLink {$link->id}: currency '{$link->currency}' is not a valid ISO 4217 code.");

            return back()->withErrors(['payment' => __('central.CheckoutInvalidPlatformCurrency', ['code' => strtoupper($link->currency)])]);
        }

        $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gatewayKey);

        try {
            $conversion = CurrencyConversionService::resolve(
                (float) $link->amount,
                $link->currency,
                $currencyConfig['supported_currencies'],
                $currencyConfig['default_currency']
            );
        } catch (\Throwable $e) {
            Log::error("PaymentLink {$link->id}: currency conversion failed — {$e->getMessage()}", ['gateway' => $gatewayKey]);

            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $payment = TenantBillingPayment::create(array_merge($base, [
            'gateway_currency'   => $conversion['gateway_currency'],
            'gateway_amount'     => $conversion['gateway_amount'],
            'exchange_rate'      => $conversion['exchange_rate'],
            'conversion_applied' => $conversion['conversion_applied'],
        ]));

        try {
            $successUrl = route('central.pay-link.return', ['token' => $token]) . '?payment=' . $payment->id;
            $cancelUrl  = route('central.pay-link.cancel', ['token' => $token]) . '?payment=' . $payment->id;

            return redirect()->away($gateway->createCheckoutSession($payment, $successUrl, $cancelUrl));
        } catch (\Throwable $e) {
            Log::error("PaymentLink {$link->id}: checkout with {$gatewayKey} failed — {$e->getMessage()}", [
                'payment_id' => $payment->id,
                'exception'  => $e,
            ]);

            $payment->markFailed();

            return back()->withErrors(['payment' => __('central.PayLinkInitFailed')]);
        }
    }

    /**
     * Gateway return leg (GET or POST — SSLCommerz posts back). Confirms the
     * attempt server-side, then redirects to the page with a clean GET.
     */
    public function handleReturn(Request $request, string $token, BillingPaymentVerifier $verifier): RedirectResponse
    {
        $link    = $this->findLink($token);
        $payment = $link->attempts()->find((int) $request->query('payment'));

        if ($payment && ! $payment->isPaid()) {
            $verifier->verify($payment);
        }

        // bKash returns cancelled/failed payers to the same callback URL with
        // ?status=cancel|failure — once the gateway has not confirmed it, treat
        // it like the cancel leg so the payer sees the right message.
        if ($payment && $payment->gateway === 'bkash' && ! $payment->isPaid()
            && in_array($request->query('status'), ['cancel', 'failure'], true)) {
            return $this->cancel($request, $token);
        }

        return redirect()->route('central.pay-link.show', array_filter([
            'token'   => $token,
            'payment' => $payment?->id,
        ]));
    }

    /** Payer backed out at the gateway. */
    public function cancel(Request $request, string $token): RedirectResponse
    {
        $link    = $this->findLink($token);
        $payment = $link->attempts()->find((int) $request->query('payment'));

        if ($payment && $payment->isPending() && $payment->gateway !== 'offline') {
            $payment->update([
                'status' => TenantBillingPayment::STATUS_FAILED,
                'notes'  => trim(($payment->notes ? $payment->notes . "\n" : '') . 'Cancelled at the gateway by the payer.'),
            ]);
        }

        return redirect()->route('central.pay-link.show', ['token' => $token, 'cancelled' => 1]);
    }

    /** Polled by the "processing" state while a webhook-only gateway settles. */
    public function status(string $token): JsonResponse
    {
        $link = $this->findLink($token);

        return response()->json([
            'status' => $link->effective_status,
            'paid'   => $link->isPaid(),
        ]);
    }

    protected function findLink(string $token): PaymentLink
    {
        return PaymentLink::where('token', $token)->firstOrFail();
    }
}
