<?php

namespace App\Services;

use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Models\WhatsappUsage;

class TenantLimitsService
{
    protected ?Plan $plan = null;
    protected ?Plan $basePlan = null;
    protected ?TenantSubscription $subscription = null;
    protected bool $resolved = false;

    /**
     * The current tenant's most recent subscription that actually grants plan
     * access — active, on trial, or cancelled but still inside the period it
     * was paid for. See TenantSubscription::scopeGrantingAccess().
     */
    protected function resolveSubscription(): ?TenantSubscription
    {
        if ($this->resolved) {
            return $this->subscription;
        }

        $this->resolved = true;
        $tenant = tenant();

        if (! $tenant) {
            return null;
        }

        $this->subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->grantingAccess()
            ->with('plan')
            ->latest()
            ->first();

        $this->basePlan = $this->subscription?->plan;
        // The effective plan: the subscription's plan plus the add-ons this
        // tenant bought. Every feature gate reads this one object, so a bought
        // module unlocks routes, sidebar, permissions and themes alike.
        $grants = \App\Models\Central\TenantAddon::activeGrantsFor((string) $tenant->id);
        $this->plan = $this->basePlan?->withAddonFeatures($grants['keys'], $grants['limits']);

        return $this->subscription;
    }

    /** The subscription's plan WITHOUT add-ons (what the tenant pays for). */
    public function getBasePlan(): ?Plan
    {
        $this->resolveSubscription();

        return $this->basePlan;
    }

    protected function resolvePlan(): ?Plan
    {
        $this->resolveSubscription();

        return $this->plan;
    }

    public function getActivePlan(): ?Plan
    {
        return $this->resolvePlan();
    }

    public function hasFeature(string $feature): bool
    {
        $plan = $this->resolvePlan();

        return $plan ? $plan->hasFeature($feature) : false;
    }

    public function getMaxAllowed(string $limitKey): ?int
    {
        $plan = $this->resolvePlan();
        if (! $plan) {
            return 0;
        }
        $max = $plan->getLimit($limitKey);
        return $max < 0 ? null : $max;
    }

    public function getCurrentUsage(string $limitKey): int
    {
        $meta = Plan::AVAILABLE_LIMITS[$limitKey] ?? null;
        if (! $meta) {
            return 0;
        }

        // custom_domains is a central table keyed by tenant, not a tenant-DB model.
        if ($limitKey === 'max_custom_domains') {
            try {
                return tenant() ? \App\Models\Central\CustomDomain::where('tenant_id', tenant()->id)->count() : 0;
            } catch (\Throwable $e) {
                return 0;
            }
        }

        // Monthly-reset counters are tracked separately, not by counting rows.
        if ($meta['monthly'] ?? false) {
            return $this->monthlyUsage($limitKey);
        }

        try {
            return Plan::countUsage($limitKey);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function hasReachedLimit(string $limitKey): bool
    {
        $plan = $this->resolvePlan();

        if (! $plan) {
            return true;
        }

        $max = $plan->getLimit($limitKey);

        if ($max < 0) {
            return false; // unlimited
        }

        $meta = Plan::AVAILABLE_LIMITS[$limitKey] ?? null;

        if (! $meta) {
            return false;
        }

        if ($meta['monthly'] ?? false) {
            return $this->monthlyUsage($limitKey) >= $max;
        }

        if (empty($meta['model']) || ! class_exists($meta['model'])) {
            return false;
        }

        return Plan::countUsage($limitKey) >= $max;
    }

    /**
     * Current usage for a monthly-reset limit key. Currently only
     * 'max_whatsapp_messages' is backed by the whatsapp_usages counter.
     */
    protected function monthlyUsage(string $limitKey): int
    {
        if ($limitKey === 'max_whatsapp_messages') {
            try {
                return WhatsappUsage::currentCount();
            } catch (\Throwable $e) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * Icon mapping from Bootstrap Icons (bi-*) to Iconsmind (i-*) for SPA.
     */
    protected function mapIcon(string $biIcon): string
    {
        $map = [
            'bi-box-seam'         => 'i-Box-Full',
            'bi-people'           => 'i-Business-ManWoman',
            'bi-building'         => 'i-Building',
            'bi-person-lines-fill'=> 'i-Checked-User',
            'bi-truck'            => 'i-Truck',
            'bi-shop'             => 'i-Shop',
            'bi-shop-window'      => 'i-Shop-2',
            'bi-cart3'            => 'i-Full-Cart',
            'bi-person-badge'     => 'i-Administrator',
            'bi-calculator'       => 'i-Calculator',
            'bi-bag-check'        => 'i-Full-Basket',
            'bi-arrow-left-right' => 'i-Repeat',
            'bi-wrench'           => 'i-Wrench',
            'bi-robot'            => 'i-Brain',
            'bi-whatsapp'         => 'i-Speach-Bubble',
            'bi-file-earmark-text'=> 'i-File-Horizontal-Text',
            'bi-kanban'           => 'i-Check',
            'bi-calendar-check'   => 'i-Calendar-4',
            'bi-pc-display'       => 'i-Monitor-3',
            'bi-file-earmark-ruled' => 'i-File-Edit',
            'bi-percent'          => 'i-Money-Bag',
            'bi-person-plus'      => 'i-Add-User',
            'bi-camera-video'     => 'i-Video',
            'bi-megaphone'        => 'i-Speaker',
            'bi-journal-text'     => 'i-Book',
            'bi-cloud-arrow-up'   => 'i-Cloud-Upload',
            'bi-qr-code'          => 'i-Bar-Code',
            'bi-broadcast'        => 'i-Wifi',
        ];

        return $map[$biIcon] ?? 'i-Gear';
    }

    public function getPlanSummary(): array
    {
        $plan = $this->resolvePlan();

        if (! $plan) {
            return [
                'has_plan' => false,
                'limits'   => [],
                'features' => [],
            ];
        }

        $limits = [];
        // Add-on keys this plan may buy (features, themes, limit packs).
        $forSale = array_flip(\App\Models\Central\Addon::saleKeysForPlan($this->basePlan));

        foreach (Plan::AVAILABLE_LIMITS as $key => $meta) {
            $max = $plan->getLimit($key);
            $unlimited = $max < 0;
            $current = 0;

            if ($meta['monthly'] ?? false) {
                $current = $this->monthlyUsage($key);
            } else {
                try {
                    $current = Plan::countUsage($key);
                } catch (\Throwable $e) {
                    $current = 0;
                }
            }

            $percentage = (!$unlimited && $max > 0) ? min(100, round(($current / $max) * 100)) : 0;

            $limits[$key] = [
                'label'      => $meta['label'],
                'icon'       => $this->mapIcon($meta['icon']),
                'current'    => $current,
                'max'        => $unlimited ? null : $max,
                'unlimited'  => $unlimited,
                'percentage' => $percentage,
                // Extra units from limit packs, and whether more can be bought.
                'addon_units' => (int) ($plan->addonLimits[$key] ?? 0),
                'purchasable' => ! $unlimited && isset($forSale[\App\Models\Central\Addon::LIMIT_PREFIX . $key]),
            ];
        }

        $features = [];
        // Features the plan lacks but the tenant may buy as an add-on: the
        // sidebar shows those modules locked, linking to Billing → Add-ons.

        foreach (Plan::AVAILABLE_FEATURES as $key => $meta) {
            $enabled = $plan->hasFeature($key);
            $features[$key] = [
                'label'       => $meta['label'],
                'icon'        => $this->mapIcon($meta['icon']),
                'enabled'     => $enabled,
                'addon'       => $plan->featureFromAddon($key),
                'purchasable' => ! $enabled && isset($forSale[$key]),
            ];
        }

        return [
            'has_plan'  => true,
            'plan_name' => $plan->name,
            'limits'    => $limits,
            'features'  => $features,
            // Billing → Add-ons menu entry (Super admin → Settings → Features).
            'addons_enabled' => \App\Models\Central\Addon::marketplaceOn(),
        ];
    }

    /**
     * Data for the in-app subscription reminder banner, or null when nothing
     * should be shown. Mirrors the email/SMS reminder rules: gated by the
     * super-admin "banner" channel + the configured threshold window.
     */
    public function getSubscriptionBanner(): ?array
    {
        $sub = $this->resolveSubscription();
        if (! $sub) {
            return null;
        }

        $setting = GeneralSetting::instance();

        // Grace period: always shown, whatever the reminder channels — the
        // workspace is about to be locked and the owner must know why.
        if ($sub->inGracePeriod()) {
            $graceEnds = $sub->graceEndsAt();
            $days = (int) now()->startOfDay()->diffInDays($graceEnds->copy()->startOfDay(), false);
            $payload = $this->bannerPayload('grace', 'danger', max(0, $days), $graceEnds, $sub->plan?->name ?? '');
            // Re-shows every day even if dismissed.
            $payload['ref'] = now()->toDateString();
            $payload['read_only'] = $setting->subscriptionGraceMode() === 'read_only';

            return $payload;
        }

        if (! $setting->reminderChannelEnabled('banner')) {
            return null;
        }

        $threshold = $setting->bannerThresholdDays();
        $planName  = $sub->plan?->name ?? '';

        // 1) Trial ending soon.
        if ($setting->trialRemindersEnabled() && $sub->isOnTrial() && $sub->trial_ends_at) {
            $days = (int) now()->startOfDay()->diffInDays($sub->trial_ends_at->copy()->startOfDay(), false);
            if ($days >= 0 && $days <= $threshold) {
                return $this->bannerPayload('trial', $days <= 1 ? 'danger' : 'warning', $days, $sub->trial_ends_at, $planName);
            }
        }

        // 2) Paid subscription expired (past end date, awaiting the daily flip)
        //    or expiring within the threshold window.
        if ($setting->remindersEnabled() && $sub->ends_at && ! $sub->isOnTrial()) {
            if ($sub->ends_at->isPast()) {
                return $this->bannerPayload('expired', 'danger', 0, $sub->ends_at, $planName);
            }

            $days = (int) now()->startOfDay()->diffInDays($sub->ends_at->copy()->startOfDay(), false);
            if ($days >= 0 && $days <= $threshold) {
                return $this->bannerPayload('expiry', $days <= 1 ? 'danger' : 'warning', $days, $sub->ends_at, $planName);
            }
        }

        return null;
    }

    protected function bannerPayload(string $type, string $level, int $days, $date, string $planName): array
    {
        return [
            'type'      => $type,             // trial | expiry | expired
            'level'     => $level,            // warning | danger
            'days'      => $days,
            'date'      => $date?->format('M d, Y') ?? '',
            'ref'       => $date?->toDateString() ?? '',
            'plan_name' => $planName,
        ];
    }

    // ─────────────────────────── WhatsApp quota ───────────────────────────

    /** WhatsApp messages sent by this tenant in the current month. */
    public function getWhatsappUsage(): int
    {
        return $this->monthlyUsage('max_whatsapp_messages');
    }

    /**
     * Whether the tenant has hit its monthly WhatsApp message quota.
     * No active plan = treated as quota reached (cannot send).
     */
    public function whatsappQuotaReached(): bool
    {
        return $this->hasReachedLimit('max_whatsapp_messages');
    }

    /**
     * Remaining WhatsApp messages this month, or null when unlimited.
     */
    public function getWhatsappRemaining(): ?int
    {
        $max = $this->getMaxAllowed('max_whatsapp_messages');

        if ($max === null) {
            return null; // unlimited
        }

        return max(0, $max - $this->getWhatsappUsage());
    }
}
