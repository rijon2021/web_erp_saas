<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Support\Facades\Log;

/**
 * Return-leg confirmation for a TenantBillingPayment outside tenant context
 * (the public /pay/{token} page). Asks the gateway about the reference WE
 * stored at checkout — never one taken from the return URL — and marks the
 * payment paid when the gateway confirms it.
 *
 * Same per-gateway calls as Tenant\BillingController::success(). PayFast and
 * WaafiPay cannot be queried by reference; they settle by webhook only.
 */
class BillingPaymentVerifier
{
    /** Gateways queried with verifyTransaction() → ['success', 'transaction_id']. */
    protected const VERIFY_TRANSACTION = ['paystack', 'flutterwave', 'bkash', 'sslcommerz', 'paymob', 'myfatoorah', 'moyasar', 'xpay'];

    /** Gateways queried with verifyPaymentStatus() → ['status', 'gateway_payment_id', 'transaction_id']. */
    protected const VERIFY_STATUS = ['stripe', 'mollie', 'razorpay', 'ligdicash', 'dodo'];

    public static function canVerify(string $gateway): bool
    {
        return $gateway === 'paypal'
            || in_array($gateway, self::VERIFY_TRANSACTION, true)
            || in_array($gateway, self::VERIFY_STATUS, true);
    }

    /**
     * Returns true when the payment is paid after the check.
     */
    public function verify(TenantBillingPayment $payment): bool
    {
        if ($payment->isPaid()) {
            return true;
        }

        // Refunded is final; failed attempts were closed by the payer or the
        // gateway. Only open (pending / superseded) attempts are re-checked.
        if (! in_array($payment->status, [TenantBillingPayment::STATUS_PENDING, TenantBillingPayment::STATUS_SUPERSEDED], true)) {
            return false;
        }

        $reference = $payment->gateway_payment_id;
        $key       = (string) $payment->gateway;

        if (! $reference || ! self::canVerify($key)) {
            return false;
        }

        try {
            $gateway = PaymentGatewayFactory::resolve($key) ?? PaymentGatewayFactory::resolveForWebhook($key);
            if (! $gateway) {
                return false;
            }

            if ($key === 'paypal') {
                /** @var \App\Services\PaymentGateways\PaypalGateway $gateway */
                $capture = $gateway->captureOrder($reference);
                if ($capture['success'] ?? false) {
                    $this->settle($payment, $capture['order_id'] ?? $reference, $capture['capture_id'] ?? null);
                }
            } elseif (in_array($key, self::VERIFY_TRANSACTION, true)) {
                $verification = $gateway->verifyTransaction($reference);
                if ($verification['success'] ?? false) {
                    $this->settle($payment, $reference, $verification['transaction_id'] ?? null);
                }
            } else {
                $verification = $gateway->verifyPaymentStatus($reference);
                if (($verification['status'] ?? '') === 'paid') {
                    $this->settle(
                        $payment,
                        $verification['gateway_payment_id'] ?? $reference,
                        $verification['transaction_id'] ?? null
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error("{$key} return-leg verification failed", [
                'payment_id' => $payment->id,
                'reference'  => $reference,
                'error'      => $e->getMessage(),
            ]);
        }

        return $payment->refresh()->isPaid();
    }

    protected function settle(TenantBillingPayment $payment, ?string $gatewayPaymentId, ?string $transactionId): void
    {
        $payment->markPaid($gatewayPaymentId ?: null, $transactionId ?: null);

        $subscription = $payment->subscription;
        if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
            $subscription->activate();
        }
    }
}
