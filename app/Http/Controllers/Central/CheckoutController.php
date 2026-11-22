<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Support\IsoCurrencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function show(string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_PROCESSING,
            ])
            ->valid()
            ->firstOrFail();

        $plan     = Plan::findOrFail($registration->plan_id);
        $gateways = $this->gatewaysForCurrency((string) $registration->currency);

        $settings = GeneralSetting::instance();
        // The registration is priced in its own currency (multi-currency
        // pricing) — show that currency on the page and in its partials.
        $code = strtoupper((string) ($registration->currency ?: ($settings->currency_code ?? 'USD')));
        \App\Support\DisplayCurrency::set($code !== \App\Support\SellingCurrencies::main() ? $code : null);

        return view('central.checkout', [
            'registration'   => $registration,
            'plan'           => $plan,
            'cycle'          => $registration->billing_cycle,
            'amount'         => $registration->amount,
            'gateways'       => $gateways,
            'currencyCode'   => $code,
            'currencySymbol' => \App\Support\SellingCurrencies::symbol($code),
            'bankDetails'    => $settings->getBankDetails(),
        ]);
    }

    public function process(Request $request, string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_PROCESSING,
            ])
            ->valid()
            ->firstOrFail();

        $rules = [
            'gateway' => ['required', 'string', 'in:stripe,paypal,paystack,flutterwave,mollie,razorpay,payfast,ligdicash,dodo,waafipay,bkash,sslcommerz,paymob,myfatoorah,moyasar,xpay,offline'],
        ];

        $gatewayKey = $request->input('gateway');

        // Offline payments require a proof of payment upload
        if ($gatewayKey === 'offline') {
            $rules['payment_proof'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }

        $request->validate($rules);

        $gateway = PaymentGatewayFactory::resolve($gatewayKey);

        if (! $gateway || ! array_key_exists($gatewayKey, $this->gatewaysForCurrency((string) $registration->currency))) {
            return back()->withErrors(['gateway' => 'Selected payment gateway is not available.']);
        }

        $plan = Plan::findOrFail($registration->plan_id);

        // ── Offline payment: save proof and mark as pending verification ──
        if ($gatewayKey === 'offline') {
            try {
                $proofPath = null;
                if ($request->hasFile('payment_proof')) {
                    $uploadDir = upload_public_path('payment-proofs');
                    if (! \Illuminate\Support\Facades\File::isDirectory($uploadDir)) {
                        \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true);
                    }
                    $file = $request->file('payment_proof');
                    $filename = 'proof_' . $registration->token . '_' . time() . '.' . \App\Support\SafeUpload::contentExtension($file, ['jpg', 'png', 'webp', 'pdf'], 'payment_proof');
                    $file->move($uploadDir, $filename);
                    $proofPath = upload_path('payment-proofs') . '/' . $filename;
                }

                $registration->update([
                    'gateway'            => 'offline',
                    'gateway_session_id' => 'offline_' . uniqid(),
                    'payment_proof_path' => $proofPath,
                    'status'             => PendingRegistration::STATUS_PROCESSING,
                ]);

                // Confirmation to the customer + review alert to the platform admin.
                \App\Services\EmailNotificationService::paymentProofSubmitted(
                    (string) $registration->admin_email,
                    $registration->company_name ?: $registration->subdomain,
                    $registration->amount,
                    $plan->name,
                    'super/payments/pending-registrations'
                );

                return redirect()->route('central.checkout.offline.submitted', ['token' => $registration->token]);
            } catch (\Throwable $e) {
                Log::error("Offline checkout failed for registration {$registration->id}: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                return back()->withErrors(['payment' => 'Unable to process your submission. Please try again.']);
            }
        }

        // ── Online payment gateways ──

        // A misspelled platform currency (Super Admin > Settings > General,
        // e.g. "DBT" instead of "BDT") can neither be converted nor charged;
        // every gateway rejects the session. Say so instead of a generic error.
        if (! IsoCurrencies::isValid($registration->currency)) {
            Log::error("Checkout blocked for registration {$registration->id}: platform currency '{$registration->currency}' is not a valid ISO 4217 code. Fix it under Super Admin > Settings > General.", [
                'gateway'    => $gatewayKey,
                'suggestion' => IsoCurrencies::suggest($registration->currency),
            ]);

            return back()->withErrors(['payment' => __('central.CheckoutInvalidPlatformCurrency', ['code' => strtoupper((string) $registration->currency)])]);
        }

        // Resolve currency conversion
        $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gatewayKey);

        try {
            $conversion = CurrencyConversionService::resolve(
                (float) $registration->amount,
                $registration->currency,
                $currencyConfig['supported_currencies'],
                $currencyConfig['default_currency']
            );
        } catch (\Throwable $e) {
            // Typically an unknown/misspelled platform currency code (e.g. "DBT"
            // instead of "BDT") that no exchange-rate provider can resolve.
            // Surface it on the checkout page instead of a bare 500.
            Log::error("Checkout currency conversion failed for registration {$registration->id}: {$e->getMessage()}", [
                'system_currency'  => $registration->currency,
                'gateway'          => $gatewayKey,
                'gateway_currency' => $currencyConfig['default_currency'],
            ]);

            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $successUrl = route('central.registration.preparing', ['token' => $registration->token]);
        $cancelUrl  = route('central.checkout', ['token' => $registration->token]) . '?cancelled=1';

        try {
            // Send gateway_amount + gateway_currency to the payment provider
            $result = $gateway->createCheckoutUrl(
                amount: $conversion['gateway_amount'],
                currency: $conversion['gateway_currency'],
                productName: $plan->name . ' Plan',
                description: ucfirst($registration->billing_cycle) . ' subscription',
                metadata: [
                    'registration_id'    => $registration->id,
                    'registration_token' => $registration->token,
                ],
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
            );

            // If a DCC fallback occurred (e.g. USD → NGN), use the actual
            // currency/amount that was sent to the gateway, not the original.
            $actualCurrency = $result['fallback_currency'] ?? $conversion['gateway_currency'];
            $actualAmount   = $result['fallback_amount']   ?? $conversion['gateway_amount'];

            $registration->update([
                'gateway'            => $gatewayKey,
                'gateway_session_id' => $result['session_id'],
                'gateway_currency'   => $actualCurrency,
                'gateway_amount'     => $actualAmount,
                'exchange_rate'      => $conversion['exchange_rate'],
                'conversion_applied' => $conversion['conversion_applied'] || ($actualCurrency !== $conversion['gateway_currency']),
                'status'             => PendingRegistration::STATUS_PROCESSING,
            ]);

            return redirect()->away($result['url']);
        } catch (\Throwable $e) {
            Log::error("Checkout failed for registration {$registration->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            return back()->withErrors(['payment' => 'Unable to initiate payment. Please try again.']);
        }
    }

    /**
     * Gateways offered for a registration. A signup in a currency other than
     * the main one (multi-currency pricing) is offered only the gateways that
     * charge that currency directly — the customer pays exactly the price they
     * saw. When none can, every gateway is offered and converts as before.
     */
    protected function gatewaysForCurrency(string $currency): array
    {
        $all = PaymentGatewayFactory::getAvailableGateways();
        $currency = strtoupper($currency);
        if ($currency === '' || $currency === \App\Support\SellingCurrencies::main()) {
            return $all;
        }

        $direct = array_intersect_key($all, \App\Support\SellingCurrencies::gatewaysFor($currency));
        // Bank transfer alone is not a real choice for an online signup unless nothing else can take it.
        $online = array_diff_key($direct, ['offline' => true]);

        return $online !== [] ? $direct : $all;
    }

    /**
     * Confirmation page after offline payment proof is submitted.
     */
    public function offlineSubmitted(string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->where('gateway', 'offline')
            ->firstOrFail();

        $plan = Plan::findOrFail($registration->plan_id);
        $settings = GeneralSetting::instance();

        return view('central.checkout_offline_submitted', [
            'registration'   => $registration,
            'plan'           => $plan,
            'appName'        => $settings->app_name ?? config('app.name'),
        ]);
    }

}
