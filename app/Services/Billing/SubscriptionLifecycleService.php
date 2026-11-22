<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Jobs\ProvisionTenantWorkspace;
use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\EmailNotificationService;
use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single owner of payment + subscription state transitions.
 *
 * Every controller, webhook, and admin action that mutates a payment or
 * subscription should funnel through here. This guarantees that:
 *  - Older pending payments are auto-superseded so the admin only sees one
 *    actionable item per subscription.
 *  - Marking a payment paid always activates the subscription and (if the
 *    tenant is still pending) dispatches the provisioning job.
 *  - Confirmation emails are sent from one place.
 */
class SubscriptionLifecycleService
{
    /**
     * Create a new payment for the given subscription. Any existing pending
     * payments on the same subscription are marked as 'superseded' so the
     * admin notification queue stays unambiguous.
     */
    public function createPayment(TenantSubscription $subscription, array $attrs): TenantBillingPayment
    {
        return DB::connection('central')->transaction(function () use ($subscription, $attrs) {
            $attrs['tenant_id']              = $subscription->tenant_id;
            $attrs['tenant_subscription_id'] = $subscription->id;
            $attrs['plan_id']                = $attrs['plan_id'] ?? $subscription->plan_id;
            $attrs['status']                 = $attrs['status'] ?? TenantBillingPayment::STATUS_PENDING;

            $payment = TenantBillingPayment::create($attrs);

            // Soft-supersede any older pending payments on the same subscription.
            TenantBillingPayment::where('tenant_subscription_id', $subscription->id)
                ->where('status', TenantBillingPayment::STATUS_PENDING)
                ->where('id', '!=', $payment->id)
                ->get()
                ->each(fn (TenantBillingPayment $old) => $old->markSuperseded($payment->id));

            return $payment;
        });
    }

    /**
     * Mark a payment paid and cascade the side-effects:
     *   - subscription activated (if it has one and isn't already active)
     *   - provisioning dispatched (if tenant is pending and not shared hosting)
     *   - confirmation email sent
     *
     * Idempotent: re-running for an already-paid payment is a no-op.
     *
     * Returns an array describing what happened — useful for flash messages.
     */
    public function markPaid(TenantBillingPayment $payment, array $gatewayMeta = []): array
    {
        // Refunded is final too — a replayed "paid" webhook must not re-activate
        // the subscription or re-send the receipt. Reported as already settled
        // so callers short-circuit.
        if ($payment->isPaid() || $payment->isRefunded()) {
            return [
                'already_paid'             => true,
                'subscription_activated'   => false,
                'subscription_renewed'     => false,
                'provisioning_dispatched'  => false,
            ];
        }

        $payment->markPaid(
            $gatewayMeta['gateway_payment_id'] ?? null,
            $gatewayMeta['transaction_id'] ?? null
        );

        $subscriptionActivated = false;
        $subscriptionRenewed = false;
        $subscription = $payment->subscription;
        if ($subscription) {
            if ($subscription->status === TenantSubscription::STATUS_ACTIVE) {
                // Already active — this is a renewal payment. Extend ends_at.
                $subscription->renew();
                $subscriptionRenewed = true;
            } else {
                // First activation (pending, failed, expired, etc.)
                $subscription->activate();
                $subscriptionActivated = true;
            }
        }

        $provisioningDispatched = false;
        $tenant = $payment->tenant;
        if ($tenant) {
            $settings = GeneralSetting::instance();
            // A payment-link charge is not a plan payment: it must not provision
            // a pending workspace on its own — the admin still approves it.
            if ($tenant->status === Tenant::STATUS_PENDING && ! $settings->isSharedHosting() && ! $payment->payment_link_id) {
                ProvisionTenantWorkspace::dispatchAfterResponse($tenant->id);
                Log::info("SubscriptionLifecycleService: provisioning dispatched for tenant {$tenant->id} after payment {$payment->id}.");
                $provisioningDispatched = true;
            }

            // The payment_success template speaks about the plan and its expiry,
            // which is wrong for a custom-amount payment link — skip it there.
            if (! $payment->payment_link_id) {
                try {
                    EmailNotificationService::paymentSuccess($tenant, [
                        '{{amount}}' => GeneralSetting::currencySymbol() . number_format((float) $payment->amount, 2),
                    ], $subscription);
                } catch (\Throwable $e) {
                    Log::warning("SubscriptionLifecycleService: paymentSuccess email failed for tenant {$tenant->id}: {$e->getMessage()}");
                }
            }
        }

        return [
            'already_paid'            => false,
            'subscription_activated'  => $subscriptionActivated,
            'subscription_renewed'    => $subscriptionRenewed,
            'provisioning_dispatched' => $provisioningDispatched,
        ];
    }

    /**
     * Mark a payment failed and notify the tenant. Subscription state is
     * intentionally left untouched — the admin decides whether to cancel.
     */
    public function markFailed(TenantBillingPayment $payment): void
    {
        if (in_array($payment->status, [
            TenantBillingPayment::STATUS_FAILED,
            TenantBillingPayment::STATUS_PAID,
            TenantBillingPayment::STATUS_REFUNDED,
        ], true)) {
            return;
        }

        $payment->markFailed();

        $tenant = $payment->tenant;
        if ($tenant && ! $payment->payment_link_id) {
            try {
                EmailNotificationService::paymentFailed($tenant, [
                    '{{amount}}' => GeneralSetting::currencySymbol() . number_format((float) $payment->amount, 2),
                ], $payment->subscription);
            } catch (\Throwable $e) {
                Log::warning("SubscriptionLifecycleService: paymentFailed email failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Mark a payment refunded. Subscription state is left to admin discretion.
     */
    public function markRefunded(TenantBillingPayment $payment, ?string $refundTransactionId = null): void
    {
        if ($payment->isRefunded()) {
            return;
        }

        $payment->markRefunded($refundTransactionId);
    }
}
