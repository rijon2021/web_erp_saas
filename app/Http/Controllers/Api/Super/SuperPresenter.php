<?php

namespace App\Http\Controllers\Api\Super;

use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\CarbonInterface;

/**
 * JSON shapes shared by the super-admin mobile API. Money is sent both as a
 * number and pre-formatted with the platform currency, so the app never
 * guesses symbols or decimals.
 */
class SuperPresenter
{
    public static function money(float|int|string|null $amount, ?string $currency = null): string
    {
        $payment = new TenantBillingPayment();
        $payment->currency = $currency;

        return $payment->formatMoney($amount, false);
    }

    public static function date(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }

    /** A stored domain may be a bare subdomain ("abc"); show the full host. */
    public static function host(string $domain): string
    {
        if (str_contains($domain, '.')) {
            return $domain;
        }

        $base = preg_replace('/^www\./i', '', request()->getHost());

        return $domain . '.' . $base;
    }

    public static function primaryHost(Tenant $tenant): ?string
    {
        $domains = $tenant->relationLoaded('domains') ? $tenant->domains : $tenant->domains()->get();
        $domain = $domains->firstWhere('is_active', true) ?? $domains->first();

        return $domain ? self::host($domain->domain) : null;
    }

    /**
     * One status word for the app's badges: the tenant status, refined for a
     * live workspace by its subscription (trial / expired).
     */
    public static function displayStatus(Tenant $tenant, ?TenantSubscription $sub): string
    {
        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            return (string) $tenant->status;
        }
        if (! $sub) {
            return 'active';
        }
        if ($sub->hasLapsed() || $sub->status === TenantSubscription::STATUS_EXPIRED) {
            return 'expired';
        }
        if ($sub->status === TenantSubscription::STATUS_TRIAL && $sub->isOnTrial()) {
            return 'trial';
        }

        return 'active';
    }

    public static function subscription(?TenantSubscription $sub): ?array
    {
        if (! $sub) {
            return null;
        }

        $endsAt = $sub->status === TenantSubscription::STATUS_TRIAL ? $sub->trial_ends_at : $sub->ends_at;

        return [
            'id'             => $sub->id,
            'status'         => $sub->status,
            'plan'           => $sub->plan?->name,
            'billing_cycle'  => $sub->billing_cycle,
            'amount'         => (float) $sub->amount,
            'amount_label'   => self::money($sub->amount, $sub->currency),
            'starts_at'      => self::date($sub->starts_at),
            'trial_ends_at'  => self::date($sub->trial_ends_at),
            'ends_at'        => self::date($endsAt),
            'days_remaining' => $endsAt ? max(0, (int) now()->diffInDays($endsAt, false)) : null,
            'cancelled'      => $sub->cancelled_at !== null,
        ];
    }

    /** Row for lists. Expects domains and subscriptions.plan eager-loaded. */
    public static function tenantSummary(Tenant $tenant): array
    {
        $sub = $tenant->relationLoaded('subscriptions')
            ? $tenant->currentSubscriptionLoaded()
            : $tenant->currentSubscription();

        return [
            'id'               => $tenant->id,
            'company_name'     => $tenant->company_name ?: $tenant->id,
            'admin_email'      => $tenant->admin_email,
            'domain'           => self::primaryHost($tenant),
            'status'           => (string) $tenant->status,
            'display_status'   => self::displayStatus($tenant, $sub),
            'suspension_reason' => $tenant->suspension_reason,
            'plan'             => $sub?->plan?->name,
            'subscription'     => self::subscription($sub),
            'created_at'       => self::date($tenant->created_at),
            'last_activity_at' => self::date($tenant->last_activity_at),
        ];
    }

    public static function payment(TenantBillingPayment $payment): array
    {
        return [
            'id'             => $payment->id,
            'invoice_number' => $payment->invoice_number,
            'status'         => $payment->status,
            'amount'         => (float) $payment->amount,
            'tax'            => (float) $payment->tax,
            'total'          => (float) $payment->total,
            'amount_label'   => $payment->formatMoney($payment->total, false),
            'currency'       => $payment->currency,
            'gateway'        => $payment->gateway,
            'gateway_label'  => $payment->gateway_label,
            'billing_cycle'  => $payment->billing_cycle,
            'plan'           => $payment->plan?->name,
            'tenant_id'      => $payment->tenant_id,
            'company_name'   => $payment->tenant?->company_name ?: $payment->tenant_id,
            'transaction_id' => $payment->display_transaction_id,
            'notes'          => $payment->notes,
            'paid_at'        => self::date($payment->paid_at),
            'created_at'     => self::date($payment->created_at),
            'can_mark_paid'   => $payment->status === TenantBillingPayment::STATUS_PENDING,
            'can_mark_failed' => $payment->status === TenantBillingPayment::STATUS_PENDING,
            'can_refund'      => $payment->status === TenantBillingPayment::STATUS_PAID,
        ];
    }

    /** A workspace waiting for manual approval (tenant status "pending"). */
    public static function pendingTenant(Tenant $tenant): array
    {
        $sub = $tenant->subscription;
        $plan = $sub?->plan;
        $freeOrTrial = $plan && ($plan->isFree() || $plan->hasTrial());
        $paid = $tenant->payments()->where('status', TenantBillingPayment::STATUS_PAID)->exists();
        $needsWeb = GeneralSetting::instance()->isSharedHosting();

        $note = null;
        if (! ($freeOrTrial || $paid)) {
            $note = 'Waiting for payment. Confirm the payment first, then approve.';
        } elseif ($needsWeb) {
            $note = 'Shared hosting: create the database in your hosting panel, then approve on the web.';
        } elseif ($tenant->provisioning_error) {
            $note = 'Last setup attempt failed: ' . $tenant->provisioning_error;
        }

        return [
            'type'           => 'tenant',
            'id'             => $tenant->id,
            'company_name'   => $tenant->company_name ?: $tenant->id,
            'admin_email'    => $tenant->admin_email,
            'phone'          => $tenant->owner_phone,
            'plan'           => $plan?->name,
            'billing_cycle'  => $sub?->billing_cycle,
            'amount_label'   => $sub ? self::money($sub->amount, $sub->currency) : null,
            'payment'        => $freeOrTrial ? ($plan?->hasTrial() ? 'Free trial' : 'Free plan') : ($paid ? 'Paid' : 'Not paid yet'),
            'domain'         => self::primaryHost($tenant),
            'created_at'     => self::date($tenant->created_at),
            'can_approve'    => ($freeOrTrial || $paid) && ! $needsWeb,
            'can_reject'     => true,
            'note'           => $note,
        ];
    }

    /** A paid-by-transfer signup waiting for the proof to be checked. */
    public static function pendingRegistration(PendingRegistration $reg): array
    {
        return [
            'type'          => 'registration',
            'id'            => $reg->id,
            'company_name'  => $reg->company_name ?: $reg->subdomain,
            'admin_email'   => $reg->admin_email,
            'phone'         => null,
            'plan'          => $reg->plan?->name,
            'billing_cycle' => $reg->billing_cycle,
            'amount_label'  => self::money($reg->amount, $reg->currency),
            'payment'       => 'Bank transfer (proof sent)',
            'domain'        => $reg->subdomain ? self::host($reg->subdomain) : null,
            'created_at'    => self::date($reg->created_at),
            'can_approve'   => true,
            'can_reject'    => true,
            'note'          => null,
        ];
    }
}
