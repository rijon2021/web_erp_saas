<?php

namespace App\Services;

use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    /**
     * Send a notification email for the given trigger key.
     *
     * @param string $triggerKey  One of EmailTemplate::TRIGGERS
     * @param Tenant $tenant      The tenant this notification is about
     * @param array  $extraVars   Additional/override variables for the template
     * @param TenantSubscription|null $subscription  The subscription this notification is about.
     *                            A tenant can have several subscription rows (old trial, pending
     *                            upgrade, …) — pass the relevant one so dates/plan resolve correctly.
     */
    public static function send(string $triggerKey, Tenant $tenant, array $extraVars = [], ?string $locale = null, ?TenantSubscription $subscription = null): bool
    {
        try {
            $subscription = $subscription ?? $tenant->activeSubscription ?? $tenant->subscription;
            $plan         = $subscription?->plan;

            $variables = array_merge([
                '{{user_name}}'   => $tenant->company_name ?? $tenant->id,
                '{{user_email}}'  => $tenant->admin_email ?? '',
                '{{expiry_date}}' => $subscription?->ends_at?->format('M d, Y') ?? 'N/A',
                '{{trial_end_date}}' => $subscription?->trial_ends_at?->format('M d, Y') ?? 'N/A',
                '{{amount}}'      => $subscription ? static::money($subscription->amount) : 'N/A',
                '{{plan_name}}'   => $plan?->name ?? 'N/A',
            ], $extraVars);
        } catch (\Throwable $e) {
            Log::error("EmailNotification: failed to build [{$triggerKey}] — {$e->getMessage()}", [
                'tenant_id' => $tenant->id,
                'exception' => $e,
            ]);

            return false;
        }

        if (empty($variables['{{user_email}}'])) {
            Log::warning("EmailNotification: no admin_email for tenant {$tenant->id}, skipping {$triggerKey}");
            return false;
        }

        return static::sendTo($triggerKey, $variables['{{user_email}}'], $variables, $locale ?? $tenant->locale ?? null);
    }

    /**
     * Send a template to any address — a signup that has no tenant yet, or
     * the platform admin. $variables are merged over app_name / app_url.
     */
    public static function sendTo(string $triggerKey, string $recipientEmail, array $variables = [], ?string $locale = null, array $escapeKeys = []): bool
    {
        try {
            $template = EmailTemplate::with('translations')
                ->where('trigger_key', $triggerKey)
                ->where('is_active', true)
                ->first();

            if (! $template || trim($recipientEmail) === '') {
                return false;
            }

            // Don't pass the default locale — let it fall back naturally
            if ($locale && $locale === CentralLanguage::defaultLocale()) {
                $locale = null;
            }

            $variables = array_merge([
                '{{user_email}}' => $recipientEmail,
                '{{app_name}}'   => config('app.name', 'Stocky'),
                '{{app_url}}'    => config('app.url', 'http://localhost'),
            ], $variables);

            $subject = $template->renderSubject($variables, $locale);
            // Values typed by the public (signup company name, errors) are escaped
            // in the HTML body; the subject is plain text and keeps them as is.
            $bodyVariables = $variables;
            foreach ($escapeKeys as $key) {
                if (isset($bodyVariables[$key])) {
                    $bodyVariables[$key] = e((string) $bodyVariables[$key]);
                }
            }
            $html    = $template->render($bodyVariables, $locale);

            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $html) {
                $message->to($recipientEmail)
                    ->subject($subject)
                    ->html($html);
            });

            Log::info("EmailNotification: sent [{$triggerKey}] to {$recipientEmail}" . ($locale ? " [locale:{$locale}]" : ''));

            return true;
        } catch (\Throwable $e) {
            Log::error("EmailNotification: failed to send [{$triggerKey}] — {$e->getMessage()}", [
                'recipient' => $recipientEmail,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /** Amount with the platform currency symbol. */
    public static function money($amount): string
    {
        try {
            $symbol = \App\Models\Central\GeneralSetting::currencySymbol();
        } catch (\Throwable) {
            $symbol = '$';
        }

        return $symbol . number_format((float) $amount, 2);
    }

    /** Central (super admin) URL for a path — admin emails may be built on a tenant domain. */
    public static function centralUrl(string $path): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/') . '/' . ltrim($path, '/');
    }

    /** The platform admin's address (General Settings > email), or null. */
    protected static function adminEmail(): ?string
    {
        try {
            $email = \App\Models\Central\GeneralSetting::instance()->email;
        } catch (\Throwable) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Welcome email once a workspace goes live (self-signup / auto-provision;
     * an admin approval sends the "approved" email instead).
     */
    public static function workspaceReady(Tenant $tenant): bool
    {
        $tenantUrl = $tenant->getTenantUrl();

        return static::send(EmailTemplate::TRIGGER_WORKSPACE_READY, $tenant, [
            '{{tenant_url}}' => $tenantUrl,
            '{{login_url}}'  => $tenantUrl !== '' ? $tenantUrl . '/login' : '',
            '{{subdomain}}'  => $tenant->domains()->first()?->domain ?? '',
        ]);
    }

    /**
     * Bank-transfer proof received — to the customer, and an alert with a
     * review link to the platform admin.
     */
    public static function paymentProofSubmitted(string $customerEmail, string $customerName, $amount, string $planName, string $reviewPath, ?string $locale = null): void
    {
        $vars = [
            '{{user_name}}'  => $customerName,
            '{{amount}}'     => static::money($amount),
            '{{plan_name}}'  => $planName,
        ];

        static::sendTo(EmailTemplate::TRIGGER_PAYMENT_PROOF_RECEIVED, $customerEmail, $vars, $locale, self::PUBLIC_KEYS);

        if ($admin = static::adminEmail()) {
            static::sendTo(EmailTemplate::TRIGGER_ADMIN_PAYMENT_PROOF, $admin, $vars + [
                '{{user_email}}' => $customerEmail,
                '{{review_url}}' => static::centralUrl($reviewPath),
            ], null, self::PUBLIC_KEYS);
        }
    }

    /** Values a member of the public typed (signup form, error text) — escaped in email bodies. */
    private const PUBLIC_KEYS = ['{{user_name}}', '{{user_email}}', '{{plan_name}}', '{{subdomain}}', '{{error_message}}'];

    /** Admin alert only (e.g. payment-link proofs, where the customer sees the status page). */
    public static function adminPaymentProof(string $customerEmail, string $customerName, $amount, string $planName, string $reviewPath): bool
    {
        $admin = static::adminEmail();

        return $admin ? static::sendTo(EmailTemplate::TRIGGER_ADMIN_PAYMENT_PROOF, $admin, [
            '{{user_name}}'  => $customerName,
            '{{user_email}}' => $customerEmail,
            '{{amount}}'     => static::money($amount),
            '{{plan_name}}'  => $planName,
            '{{review_url}}' => static::centralUrl($reviewPath),
        ], null, self::PUBLIC_KEYS) : false;
    }

    /** A signup's bank-transfer proof was rejected. */
    public static function paymentProofRejected(string $customerEmail, string $customerName, $amount, string $planName, ?string $locale = null): bool
    {
        return static::sendTo(EmailTemplate::TRIGGER_PAYMENT_PROOF_REJECTED, $customerEmail, [
            '{{user_name}}'       => $customerName,
            '{{amount}}'          => static::money($amount),
            '{{plan_name}}'       => $planName,
            '{{resubscribe_url}}' => static::centralUrl('register'),
        ], $locale, self::PUBLIC_KEYS);
    }

    /** Alert the platform admin that a workspace could not be provisioned. */
    public static function adminProvisioningFailed(Tenant $tenant, string $error): bool
    {
        $admin = static::adminEmail();

        return $admin ? static::sendTo(EmailTemplate::TRIGGER_ADMIN_PROVISIONING_FAILED, $admin, [
            '{{user_name}}'     => $tenant->company_name ?? $tenant->id,
            '{{subdomain}}'     => $tenant->domains()->first()?->domain ?? '',
            '{{error_message}}' => \Illuminate\Support\Str::limit($error, 1000),
            '{{admin_url}}'     => static::centralUrl('super/tenants/' . $tenant->id),
        ], null, self::PUBLIC_KEYS) : false;
    }

    /**
     * Shorthand: subscription expired.
     */
    public static function subscriptionExpired(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_SUBSCRIPTION_EXPIRED, $tenant, $extra);
    }

    /**
     * Shorthand: subscription expiring soon.
     */
    public static function expiringSoon(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_EXPIRING_SOON, $tenant, $extra);
    }

    /**
     * Shorthand: trial ending soon.
     */
    public static function trialEnding(Tenant $tenant, array $extra = []): bool
    {
        return static::send(EmailTemplate::TRIGGER_TRIAL_ENDING, $tenant, $extra);
    }

    /**
     * Shorthand: payment success.
     */
    public static function paymentSuccess(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_SUCCESS, $tenant, $extra, null, $subscription);
    }

    /**
     * Shorthand: payment failed.
     */
    public static function paymentFailed(Tenant $tenant, array $extra = [], ?TenantSubscription $subscription = null): bool
    {
        return static::send(EmailTemplate::TRIGGER_PAYMENT_FAILED, $tenant, $extra, null, $subscription);
    }

    /**
     * Shorthand: plan ended (cancelled subscription reached expiry).
     */
    public static function planEnded(Tenant $tenant, array $extra = []): bool
    {
        // Domains are stored as bare subdomains — getTenantUrl() adds the base
        // host and scheme. /billing/plans stays reachable after expiry (and
        // during a non-payment suspension), unlike the in-app plan page.
        $tenantUrl = $tenant->getTenantUrl();
        $resubscribeUrl = $tenantUrl !== ''
            ? $tenantUrl . '/billing/plans'
            : rtrim((string) config('app.url'), '/');

        $extra = array_merge([
            '{{resubscribe_url}}' => $resubscribeUrl,
        ], $extra);

        return static::send(EmailTemplate::TRIGGER_PLAN_ENDED, $tenant, $extra);
    }

    /**
     * Resolve the tenant's preferred locale (if set).
     */
    public static function tenantLocale(Tenant $tenant): ?string
    {
        return $tenant->locale ?? null;
    }
}
