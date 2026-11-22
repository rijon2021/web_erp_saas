<?php

namespace App\Services\Billing;

use App\Models\Central\Addon;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaymentLink;
use App\Models\Central\Plan;
use App\Models\Central\TenantAddon;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Buying, renewing, trialling, granting and expiring add-ons.
 *
 * Kinds: modules (plan feature keys), premium themes ("theme:<key>") and limit
 * packs ("limit:<limit key>", e.g. +5 users per pack, stackable).
 *
 * Payment goes through a PaymentLink (payment_links.tenant_addon_id), so the
 * hosted /pay/{token} page, every gateway, bank-transfer approval, webhooks
 * and refunds work exactly as for super-admin payment links. When the link is
 * settled, PaymentLink::markPaidFrom() calls onLinkPaid() here.
 *
 * Billing: the first purchase of a monthly/yearly add-on is prorated to end
 * with the tenant's paid plan period when both use the same cycle. From then
 * on such an add-on is billed WITH the plan: renewing the same plan adds a
 * line per add-on to that payment (planRenewalLines), and when the renewed
 * subscription is saved the add-ons are extended to its new end
 * (applyPlanPayments). Other add-ons renew through their own payment link.
 * One-time ("lifetime") add-ons never end. Access always also needs a plan
 * that grants access — add-ons are merged into the plan, never replace it.
 */
class AddonService
{
    /** Days before the end date the renewal reminder (with a pay link) goes out. */
    public const REMINDER_DAYS = 7;

    /** Days before a free trial ends the "buy it now" reminder goes out. */
    public const TRIAL_REMINDER_DAYS = 2;

    /** Days a purchase / renewal payment link stays payable. */
    public const LINK_DAYS = 7;

    // ── Catalogue as one tenant sees it ─────────────────────────────

    /**
     * Every add-on for sale to this tenant, with its state:
     * included | active | trial | pending | available.
     *
     * @return array<int, array<string, mixed>>
     */
    public function offersFor(Tenant $tenant): array
    {
        if (! Addon::marketplaceOn()) {
            return [];
        }

        $subscription = $this->grantingSubscription($tenant);
        $plan = $subscription?->plan;
        $catalog = Addon::catalog();
        $effective = $this->effectiveKeys($tenant, $plan);
        $tenantCurrency = PlanPricing::tenantCurrency($tenant);

        $history = TenantAddon::where('tenant_id', $tenant->id)->with('paymentLink')->latest('id')->get();
        $rows = $history->whereIn('status', [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_PENDING, TenantAddon::STATUS_EXPIRED])
            ->groupBy('addon_key');
        $everHad = $history->pluck('addon_key')->flip();

        $out = [];
        foreach (Addon::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $addon) {
            if (! $addon->isForSale() || ! isset($catalog[$addon->key])) {
                continue;
            }

            $mine = $rows->get($addon->key, collect());
            $granting = $mine->filter(fn (TenantAddon $r) => $r->isGranting());
            $active = $granting->first(fn (TenantAddon $r) => ! $r->isTrial());
            $trial = $granting->first(fn (TenantAddon $r) => $r->isTrial());
            $pending = $mine->first(fn (TenantAddon $r) => $r->status === TenantAddon::STATUS_PENDING);
            $expired = ($active || $trial) ? null : $mine->first(fn (TenantAddon $r) => ! $r->isTrial()
                && ($r->status === TenantAddon::STATUS_EXPIRED || ($r->status === TenantAddon::STATUS_ACTIVE && ! $r->isGranting())));
            $included = $this->planIncludes($plan, $addon->key);
            $isPack = $addon->isLimitPack();

            // Not offered to this plan: only shown when the tenant already owns it.
            if (! $addon->offeredToPlan($plan) && ! $active) {
                continue;
            }

            $state = match (true) {
                $included                => 'included',
                $isPack                  => $pending ? 'pending' : 'available', // packs stack: always buyable
                (bool) $active           => 'active',
                (bool) $pending          => 'pending',
                (bool) $trial            => 'trial',
                default                  => 'available',
            };

            $prices = [];
            foreach ($addon->cycles() as $cycle) {
                $quote = $this->quote($addon, $cycle, $subscription);
                // Shown in the workspace's billing currency (priced in the main one).
                try {
                    $shownPrice = PlanPricing::fromMain((float) $addon->priceFor($cycle), $tenantCurrency);
                    $shownDue = PlanPricing::fromMain((float) $quote['amount'], $tenantCurrency);
                } catch (\Throwable) {
                    continue; // no rate right now — this option can't be priced
                }
                $prices[] = [
                    'cycle'     => $cycle,
                    'price'     => $shownPrice,
                    'first_due' => $shownDue,
                    'prorated'  => $quote['aligned'],
                    // Share of a full period charged now (packs: price × packs × ratio).
                    'ratio'     => round((float) $quote['ratio'], 6),
                    'ends_at'   => $quote['ends_at']?->toIso8601String(),
                ];
            }

            $requires = [];
            foreach ($addon->requiredKeys() as $req) {
                $requires[] = [
                    'key'  => $req,
                    'name' => $catalog[$req]['label'] ?? $req,
                    'met'  => isset($effective[$req]),
                ];
            }

            $link = $pending?->paymentLink;
            $renewLink = $active?->paymentLink && $active->paymentLink->isPayable() ? $active->paymentLink : null;

            $out[] = [
                'key'         => $addon->key,
                'type'        => $addon->type,
                'name'        => $addon->displayName(),
                'description' => $addon->displayDescription(),
                'icon'        => $catalog[$addon->key]['icon'],
                'state'       => $state,
                'prices'      => $prices,
                'currency'    => $tenantCurrency,
                'symbol'      => \App\Support\SellingCurrencies::symbol($tenantCurrency),
                'requires'    => $requires,
                'blocked'     => collect($requires)->contains(fn ($r) => ! $r['met']),
                'pack'        => $isPack ? [
                    'size'        => $addon->packSize(),
                    'max'         => Addon::MAX_PACKS,
                    'base'        => $plan ? $plan->getLimit((string) $addon->limitKey()) : null,
                    'owned_units' => (int) $granting->sum(fn (TenantAddon $r) => $r->limitUnits()),
                    'owned'       => $granting->map(fn (TenantAddon $r) => [
                        'id'            => $r->id,
                        'quantity'      => (int) ($r->quantity ?? 1),
                        'units'         => $r->limitUnits(),
                        'billing_cycle' => $r->billing_cycle,
                        'ends_at'       => $r->ends_at?->toIso8601String(),
                        'renewable'     => $r->isRecurring() && ! $this->billedWithPlan($r, $subscription),
                        'with_plan'     => $this->billedWithPlan($r, $subscription),
                    ])->values()->all(),
                ] : null,
                'trial'       => ! $included && ! $active && ! $trial && ! $everHad->has($addon->key) && $addon->trialDays() > 0
                    ? ['days' => $addon->trialDays()]
                    : null,
                'trial_active' => $trial ? ['id' => $trial->id, 'ends_at' => $trial->ends_at?->toIso8601String()] : null,
                'active'      => $active && ! $isPack ? [
                    'id'            => $active->id,
                    'billing_cycle' => $active->billing_cycle,
                    'ends_at'       => $active->ends_at?->toIso8601String(),
                    'renewable'     => $active->isRecurring() && $addon->priceFor($active->billing_cycle) !== null
                        && ! $this->billedWithPlan($active, $subscription),
                    'with_plan'     => $this->billedWithPlan($active, $subscription),
                    'renew_url'     => $renewLink?->url(),
                ] : null,
                'expired'     => $expired && ! $active && ! $isPack ? [
                    'id'            => $expired->id,
                    'billing_cycle' => $expired->billing_cycle,
                    'ended_at'      => $expired->ends_at?->toIso8601String(),
                    'renewable'     => $expired->isRecurring() && $addon->priceFor($expired->billing_cycle) !== null,
                ] : null,
                'pending'     => $pending ? [
                    'id'            => $pending->id,
                    'billing_cycle' => $pending->billing_cycle,
                    'quantity'      => (int) ($pending->quantity ?? 1),
                    'amount'        => (float) $pending->amount,
                    'pay_url'       => $link && $link->isPayable() ? $link->url() : null,
                ] : null,
            ];
        }

        return $out;
    }

    /** The plan covers this add-on by itself (no purchase needed). */
    public function planIncludes(?Plan $plan, string $key): bool
    {
        if (! $plan) {
            return false;
        }
        if (str_starts_with($key, Addon::THEME_PREFIX)) {
            return $plan->hasFeature(\App\Support\StoreThemes::PREMIUM_FEATURE) || $plan->hasFeature($key);
        }
        if (str_starts_with($key, Addon::LIMIT_PREFIX)) {
            // Unlimited already — nothing to add.
            return $plan->getLimit(substr($key, strlen(Addon::LIMIT_PREFIX))) < 0;
        }

        return $plan->hasFeature($key);
    }

    /**
     * What the first payment for this option costs, and until when it runs.
     *
     * @return array{amount: float, aligned: bool, ends_at: ?Carbon, ratio: float}
     */
    public function quote(Addon $addon, string $cycle, ?TenantSubscription $subscription, ?Carbon $from = null, int $quantity = 1): array
    {
        $price = (float) $addon->priceFor($cycle) * max(1, $quantity);
        $from = $from ?: now();

        if ($cycle === 'lifetime') {
            return ['amount' => round($price, 2), 'aligned' => false, 'ends_at' => null, 'ratio' => 1.0];
        }

        $fullEnd = $this->addCycle($from->copy(), $cycle);

        // Align with a paid plan period on the same cycle: charge only the part
        // left until the plan renews, so both renew together.
        if ($this->alignable($subscription, $cycle, $from) && $subscription->ends_at->lessThan($fullEnd)) {
            $period = max(1, $from->copy()->diffInSeconds($fullEnd, true));
            $left = $from->copy()->diffInSeconds($subscription->ends_at, true);
            $ratio = min(1, $left / $period);

            return ['amount' => self::prorate($price, $ratio), 'aligned' => true, 'ends_at' => $subscription->ends_at->copy(), 'ratio' => $ratio];
        }

        return ['amount' => round($price, 2), 'aligned' => false, 'ends_at' => $fullEnd, 'ratio' => 1.0];
    }

    /**
     * Prorated charge. Gateways refuse tiny charges, so never less than 1 unit
     * (or the price itself). BillingAddons.vue mirrors this for its estimate.
     */
    public static function prorate(float $price, float $ratio): float
    {
        return max(round($price * $ratio, 2), min($price, 1.0));
    }

    // ── Purchase / trial / renewal ──────────────────────────────────

    /**
     * Start buying an add-on: a pending TenantAddon plus a payment link the
     * tenant is sent to. Re-uses an unpaid link for the same option.
     */
    public function startPurchase(Tenant $tenant, string $key, string $cycle, int $quantity = 1): PaymentLink
    {
        if (! Addon::marketplaceOn()) {
            throw new \DomainException(__('super.addons.err_not_for_sale'));
        }
        $addon = Addon::where('key', $key)->first();
        $subscription = $this->grantingSubscription($tenant);

        if (! $addon || ! $addon->isForSale() || $addon->priceFor($cycle) === null) {
            throw new \DomainException(__('super.addons.err_not_for_sale'));
        }
        $quantity = $addon->isLimitPack() ? max(1, min(Addon::MAX_PACKS, $quantity)) : 1;

        $this->assertCanAcquire($tenant, $addon, $subscription);
        if (! $addon->isLimitPack() && TenantAddon::where('tenant_id', $tenant->id)->where('addon_key', $key)->granting()
            ->where('billing_cycle', '!=', TenantAddon::CYCLE_TRIAL)->exists()) {
            throw new \DomainException(__('super.addons.err_already_active'));
        }

        $quote = $this->quote($addon, $cycle, $subscription, null, $quantity);
        // Charged in the workspace's billing currency (add-ons are priced in the main one).
        $currency = PlanPricing::tenantCurrency($tenant);
        try {
            $quote['amount'] = PlanPricing::fromMain((float) $quote['amount'], $currency);
        } catch (\Throwable $e) {
            throw new \DomainException(__('super.addons.err_no_rate', ['currency' => $currency]));
        }

        return DB::connection('central')->transaction(function () use ($tenant, $addon, $cycle, $quote, $quantity, $currency) {
            // An unpaid purchase of the same add-on is replaced (or re-used as is).
            $pending = TenantAddon::where('tenant_id', $tenant->id)
                ->where('addon_key', $addon->key)
                ->where('status', TenantAddon::STATUS_PENDING)
                ->lockForUpdate()->get();

            foreach ($pending as $old) {
                $link = $old->paymentLink;
                if ($old->billing_cycle === $cycle && (int) ($old->quantity ?? 1) === $quantity
                    && $link && $link->isPayable() && abs((float) $link->amount - $quote['amount']) < 0.01) {
                    return $link;
                }
                if ($link && $link->pendingOfflineAttempt()) {
                    throw new \DomainException(__('super.addons.err_proof_waiting'));
                }
                $this->cancelPending($old);
            }

            $attrs = [
                'tenant_id'     => $tenant->id,
                'addon_key'     => $addon->key,
                'billing_cycle' => $cycle,
                'amount'        => $quote['amount'],
                'currency'      => $currency,
                'status'        => TenantAddon::STATUS_PENDING,
                'source'        => TenantAddon::SOURCE_PURCHASE,
                'metadata'      => array_filter([
                    'aligned' => $quote['aligned'],
                    // A prorated price only buys the time up to this date: the
                    // activation uses it as is, never "now + a full cycle".
                    'paid_until' => $quote['aligned'] ? $quote['ends_at']?->toIso8601String() : null,
                    'units'   => $addon->isLimitPack() ? $quantity * $addon->packSize() : null,
                ], fn ($v) => $v !== null),
            ];
            if (Addon::extrasReady()) {
                $attrs['quantity'] = $quantity;
            }
            $row = TenantAddon::create($attrs);

            $link = $this->createLink($tenant, $row, $addon, $quote['amount'], $quote['ends_at']);
            $row->update(['payment_link_id' => $link->id]);

            return $link;
        });
    }

    /** Start the free trial of an add-on — once per tenant and add-on. */
    public function startTrial(Tenant $tenant, string $key): TenantAddon
    {
        $addon = Addon::marketplaceOn() ? Addon::where('key', $key)->first() : null;
        if (! $addon || ! $addon->isForSale() || $addon->trialDays() <= 0) {
            throw new \DomainException(__('super.addons.err_no_trial'));
        }

        $this->assertCanAcquire($tenant, $addon, $this->grantingSubscription($tenant));

        if (TenantAddon::where('tenant_id', $tenant->id)->where('addon_key', $key)->exists()) {
            throw new \DomainException(__('super.addons.err_trial_used'));
        }

        return TenantAddon::create([
            'tenant_id'     => $tenant->id,
            'addon_key'     => $key,
            'billing_cycle' => TenantAddon::CYCLE_TRIAL,
            'amount'        => 0,
            'currency'      => GeneralSetting::currencyCode(),
            'status'        => TenantAddon::STATUS_ACTIVE,
            'source'        => TenantAddon::SOURCE_TRIAL,
            'starts_at'     => now(),
            'ends_at'       => now()->addDays($addon->trialDays()),
        ]);
    }

    /** A payment link renewing an active (or lapsed) recurring add-on for one more cycle. */
    public function startRenewal(TenantAddon $row): PaymentLink
    {
        $addon = Addon::where('key', $row->addon_key)->first();
        $unit = $addon?->priceFor((string) $row->billing_cycle);

        if (! $row->isRecurring() || $unit === null || ! $addon->is_active) {
            throw new \DomainException(__('super.addons.err_not_renewable'));
        }
        if (! in_array($row->status, [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_EXPIRED], true)) {
            throw new \DomainException(__('super.addons.err_not_renewable'));
        }
        if ($why = $this->renewalBlocker($row, $addon)) {
            throw new \DomainException($why);
        }
        // In the workspace's CURRENT billing currency — not the one stored on
        // the row at purchase, which goes stale if the platform currency (or
        // the workspace's) is changed later.
        $rowCurrency = PlanPricing::tenantCurrency($row->tenant ?: Tenant::find($row->tenant_id));
        if ($row->currency !== $rowCurrency) {
            $row->update(['currency' => $rowCurrency]);
        }
        try {
            $price = PlanPricing::fromMain(round($unit * max(1, (int) ($row->quantity ?? 1)), 2), $rowCurrency);
        } catch (\Throwable $e) {
            throw new \DomainException(__('super.addons.err_no_rate', ['currency' => $rowCurrency]));
        }

        $current = $row->paymentLink;
        if ($current && $current->isPayable() && ($current->tenant_addon_id ?? null) == $row->id
            && abs((float) $current->amount - $price) < 0.01) {
            return $current;
        }

        $tenant = $row->tenant ?: Tenant::findOrFail($row->tenant_id);
        $base = $row->ends_at && $row->ends_at->isFuture() ? $row->ends_at->copy() : now();
        $link = $this->createLink($tenant, $row, $addon, $price, $this->addCycle($base, (string) $row->billing_cycle), true);
        $row->update(['payment_link_id' => $link->id]);

        return $link;
    }

    /**
     * Why this row must not be renewed (null = fine): the tenant's plan now
     * includes it or no longer offers it, or a newer copy of it exists (renewing
     * an old row next to it would leave two running copies).
     */
    protected function renewalBlocker(TenantAddon $row, Addon $addon): ?string
    {
        $tenant = $row->tenant ?: Tenant::find($row->tenant_id);
        $plan = $tenant ? $this->grantingSubscription($tenant)?->plan : null;
        if (! $plan) {
            return __('super.addons.err_no_plan');
        }
        if ($this->planIncludes($plan, $row->addon_key)) {
            return __('super.addons.err_included');
        }
        if (! $addon->offeredToPlan($plan)) {
            return __('super.addons.err_not_for_plan');
        }
        if (! $row->isLimitPack() && TenantAddon::where('tenant_id', $row->tenant_id)
            ->where('addon_key', $row->addon_key)->where('id', '>', $row->id)
            ->where('billing_cycle', '!=', TenantAddon::CYCLE_TRIAL)
            ->whereIn('status', [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_PENDING])->exists()) {
            return __('super.addons.err_not_renewable');
        }

        return null;
    }

    /** PaymentLink::markPaidFrom() hook: activate or extend the add-on. Idempotent per link. */
    public function onLinkPaid(PaymentLink $link, TenantBillingPayment $payment): void
    {
        $row = TenantAddon::find($link->tenant_addon_id);
        if (! $row) {
            return;
        }

        // An admin took it away (or it was refunded): money arriving on an old
        // link must not bring it back — flag it for a refund instead.
        if (in_array($row->status, [TenantAddon::STATUS_REVOKED, TenantAddon::STATUS_REFUNDED], true)) {
            Log::warning("AddonService: payment {$payment->id} arrived for {$row->status} tenant add-on {$row->id} (tenant {$row->tenant_id}) — review for a refund.");

            return;
        }

        $meta = $row->metadata ?? [];
        $settled = (array) ($meta['settled_links'] ?? []);
        if (in_array((int) $link->id, array_map('intval', $settled), true)) {
            return; // this link was already applied
        }
        $settled[] = (int) $link->id;
        $meta['settled_links'] = array_slice($settled, -50);
        $meta['last_payment_id'] = $payment->id;

        if ($row->status === TenantAddon::STATUS_PENDING || $row->status === TenantAddon::STATUS_CANCELLED) {
            // First purchase.

            // Another copy of this module / theme is already running (e.g. a
            // second checkout finished for a purchase that was replaced): never
            // activate a duplicate — it would be billed on every plan renewal.
            if (! $row->isLimitPack() && TenantAddon::where('tenant_id', $row->tenant_id)
                ->where('addon_key', $row->addon_key)->where('id', '!=', $row->id)
                ->where('billing_cycle', '!=', TenantAddon::CYCLE_TRIAL)->granting()->exists()) {
                $this->refuseLatePayment($row, $meta, $payment, 'the add-on was already active from another purchase');

                return;
            }

            $endsAt = null;
            if ($row->billing_cycle !== 'lifetime') {
                if (! empty($meta['aligned'])) {
                    // Prorated price: it bought exactly the time up to the
                    // quoted date — not a fresh full cycle if paid late.
                    $endsAt = ! empty($meta['paid_until']) ? Carbon::parse($meta['paid_until']) : null;
                    if (! $endsAt || ! $endsAt->isFuture()) {
                        $this->refuseLatePayment($row, $meta, $payment, 'the prorated period it paid for had already ended');

                        return;
                    }
                } else {
                    $endsAt = $this->addCycle(now(), (string) $row->billing_cycle);
                }
            }
            $meta['periods'][(string) $link->id] = ['from' => now()->toIso8601String(), 'to' => $endsAt?->toIso8601String()];

            $row->update([
                'status'       => TenantAddon::STATUS_ACTIVE,
                'starts_at'    => now(),
                'ends_at'      => $endsAt,
                'cancelled_at' => null,
                'metadata'     => $meta,
            ]);

            // Bought during its free trial: the trial is over.
            TenantAddon::where('tenant_id', $row->tenant_id)->where('addon_key', $row->addon_key)
                ->where('billing_cycle', TenantAddon::CYCLE_TRIAL)->where('status', TenantAddon::STATUS_ACTIVE)
                ->get()->each(fn (TenantAddon $t) => $t->update(['status' => TenantAddon::STATUS_EXPIRED, 'ends_at' => now()]));
        } else {
            // Renewal: one more cycle from the current end (or from now when lapsed).
            $base = $row->ends_at && $row->ends_at->isFuture() ? $row->ends_at->copy() : now();
            $newEnd = $this->addCycle($base->copy(), (string) $row->billing_cycle);
            unset($meta['reminded_for']);
            $meta['periods'][(string) $link->id] = ['from' => $base->toIso8601String(), 'to' => $newEnd->toIso8601String()];
            $row->update([
                'status'   => TenantAddon::STATUS_ACTIVE,
                'ends_at'  => $newEnd,
                'amount'   => $link->amount,
                'metadata' => $meta,
            ]);
        }

        Log::info("AddonService: tenant {$row->tenant_id} add-on '{$row->addon_key}' paid by billing payment {$payment->id}; active until " . ($row->ends_at?->toDateTimeString() ?? 'forever') . '.');

        $this->notifyActivated($row);
    }

    /**
     * Money arrived but must not activate anything: keep the row inactive and
     * flag the payment for a manual refund (the money has moved already).
     */
    protected function refuseLatePayment(TenantAddon $row, array $meta, TenantBillingPayment $payment, string $why): void
    {
        $meta['refund_due'] = ['payment_id' => $payment->id, 'reason' => $why];
        $row->update([
            'status'       => TenantAddon::STATUS_CANCELLED,
            'cancelled_at' => $row->cancelled_at ?? now(),
            'metadata'     => $meta,
            'notes'        => trim(($row->notes ? $row->notes . "\n" : '') . "Paid by payment #{$payment->id} but not activated: {$why}. Refund it."),
        ]);
        Log::warning("AddonService: payment {$payment->id} for tenant add-on {$row->id} (tenant {$row->tenant_id}) not applied — {$why}. Review for a refund.");
    }

    /**
     * PaymentLink::markRefundedFrom() hook: take back exactly the period that
     * link paid for. Refunding an old link after a renewal shortens the add-on
     * by that link's period instead of wiping the renewal the tenant also paid.
     */
    public function onLinkRefunded(PaymentLink $link): void
    {
        $row = TenantAddon::find($link->tenant_addon_id);
        if (! $row || $row->status === TenantAddon::STATUS_REFUNDED) {
            return;
        }

        $meta = $row->metadata ?? [];
        $done = array_map('intval', (array) ($meta['refunded_links'] ?? []));
        if (in_array((int) $link->id, $done, true)) {
            return;
        }
        $meta['refunded_links'] = array_merge($done, [(int) $link->id]);
        $note = trim(($row->notes ? $row->notes . "\n" : '') . 'Refunded (payment link #' . $link->id . ').');

        $period = $meta['periods'][(string) $link->id] ?? null;
        $newEnd = null;
        if ($period && ! empty($period['to']) && ! empty($period['from']) && $row->ends_at) {
            $seconds = max(0, Carbon::parse($period['to'])->getTimestamp() - Carbon::parse($period['from'])->getTimestamp());
            $newEnd = $row->ends_at->copy()->subSeconds($seconds);
        }

        if ($newEnd && $newEnd->isFuture()) {
            // Other paid periods remain: keep it, minus the refunded one.
            $row->update(['ends_at' => $newEnd, 'metadata' => $meta, 'notes' => $note]);

            return;
        }

        $row->update([
            'status'       => TenantAddon::STATUS_REFUNDED,
            'ends_at'      => now(),
            'cancelled_at' => now(),
            'metadata'     => $meta,
            'notes'        => $note,
        ]);
    }

    /**
     * TenantBillingPayment hook: a plan payment that carried add-on lines was
     * refunded — take back the time applyPlanPayments() added to each add-on.
     */
    public function onPlanPaymentRefunded(TenantBillingPayment $payment): void
    {
        $meta = $payment->metadata ?? [];
        $changes = (array) ($meta['addons_previous'] ?? []);
        if (! $changes || ! empty($meta['addons_refunded'])) {
            return;
        }

        foreach ($changes as $rowId => $change) {
            $row = TenantAddon::where('tenant_id', $payment->tenant_id)->find((int) $rowId);
            if (! $row || ! $row->ends_at || empty($change['new'])) {
                continue;
            }
            $prev = ! empty($change['prev']) ? Carbon::parse($change['prev']) : now();
            $added = max(0, Carbon::parse($change['new'])->getTimestamp() - $prev->getTimestamp());
            $newEnd = $row->ends_at->copy()->subSeconds($added);
            $row->update($newEnd->isFuture()
                ? ['ends_at' => $newEnd]
                : ['ends_at' => $newEnd, 'status' => in_array($row->status, [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_EXPIRED], true) ? TenantAddon::STATUS_EXPIRED : $row->status]);
        }

        $meta['addons_refunded'] = now()->toIso8601String();
        $payment->metadata = $meta;
        $payment->saveQuietly();
    }

    // ── Billed with the plan ────────────────────────────────────────

    /**
     * Add-ons to charge on a renewal of the tenant's CURRENT plan: active,
     * bought, on the same cycle, running out with the plan period. Upgrades
     * and plan changes get no lines — those add-ons renew on their own link.
     *
     * @return array<int, array{id: int, key: string, name: string, quantity: int, amount: float}>
     */
    public function planRenewalLines(Tenant $tenant, Plan $plan, string $cycle): array
    {
        if (! Addon::tablesReady()) {
            return [];
        }

        $subscription = $this->grantingSubscription($tenant);
        if (! $subscription || (int) $subscription->plan_id !== (int) $plan->id
            || $subscription->status !== TenantSubscription::STATUS_ACTIVE || ! $subscription->ends_at
            // A free plan has no checkout to carry the lines.
            || $plan->getPriceForCycle($cycle) <= 0) {
            return [];
        }

        $lines = [];
        $rows = TenantAddon::where('tenant_id', $tenant->id)
            ->where('status', TenantAddon::STATUS_ACTIVE)
            ->where('source', TenantAddon::SOURCE_PURCHASE)
            ->where('billing_cycle', $cycle)
            ->whereNotNull('ends_at')
            ->get()
            // Only add-ons that really run with the plan period (end with it,
            // same cycle). Others renew on their own link — charging them here
            // too would bill them twice.
            ->filter(fn (TenantAddon $row) => $this->billedWithPlan($row, $subscription));
        $addons = Addon::whereIn('key', $rows->pluck('addon_key')->unique()->all())->get()->keyBy('key');

        foreach ($rows as $row) {
            $addon = $addons->get($row->addon_key);
            $unit = $addon?->priceFor($cycle);
            if ($unit === null || ! $addon->is_active || $this->planIncludes($plan, $row->addon_key) || ! $addon->offeredToPlan($plan)) {
                continue;
            }
            $quantity = max(1, (int) ($row->quantity ?? 1));
            $lines[] = [
                'id'       => $row->id,
                'key'      => $row->addon_key,
                'name'     => $addon->displayName() . ($quantity > 1 ? ' × ' . $quantity : ''),
                'quantity' => $quantity,
                'amount'   => round($unit * $quantity, 2),
                // Its end when this payment was priced: if it moves before the
                // payment is applied (renewed on its own link meanwhile), the
                // plan payment adds one more cycle instead of being swallowed.
                'row_ends_at' => $row->ends_at->toIso8601String(),
            ];
        }

        return $lines;
    }

    /**
     * A plan payment's total with its add-on lines, and the metadata to store
     * on the payment. Used by every plan checkout (SPA + Blade, online + bank
     * transfer) BEFORE currency conversion, so the gateway charges the total.
     *
     * @return array{amount: float, lines: array, metadata: array}
     */
    public function planCharge(?Tenant $tenant, Plan $plan, string $cycle, float $planAmount, ?string $currency = null): array
    {
        try {
            $lines = $tenant ? $this->planRenewalLines($tenant, $plan, $cycle) : [];
            // Add-ons are priced in the main currency; a plan payment in the
            // workspace's own currency (multi-currency pricing) needs them in it.
            $currency = strtoupper((string) ($currency ?: \App\Support\SellingCurrencies::main()));
            if ($lines && $currency !== \App\Support\SellingCurrencies::main()) {
                foreach ($lines as &$line) {
                    $line['amount'] = PlanPricing::fromMain((float) $line['amount'], $currency);
                }
                unset($line);
            }
        } catch (\Throwable $e) {
            // No rate right now: leave the add-ons off this payment; they renew on their own link.
            Log::warning("AddonService: add-on lines for tenant {$tenant?->id} failed: {$e->getMessage()}");
            $lines = [];
        }

        $total = round(array_sum(array_column($lines, 'amount')), 2);

        return [
            'amount'   => round($planAmount + $total, 2),
            'lines'    => $lines,
            'metadata' => $lines ? [
                'addons'       => $lines,
                'addons_total' => $total,
                'plan_amount'  => round($planAmount, 2),
                // Shown on the gateway's hosted page (checkoutDescription()).
                'checkout_description' => ucfirst($cycle) . ' subscription + ' . count($lines) . ' add-on' . (count($lines) > 1 ? 's' : ''),
            ] : [],
        ];
    }

    /**
     * After a subscription was activated / renewed: extend the add-ons that
     * were paid on its plan payment(s) to the subscription's new end.
     * Idempotent (metadata.addons_applied on the payment).
     */
    public function applyPlanPayments(TenantSubscription $subscription): int
    {
        if (! Addon::tablesReady() || $subscription->status !== TenantSubscription::STATUS_ACTIVE || ! $subscription->ends_at) {
            return 0;
        }

        $applied = 0;
        $payments = TenantBillingPayment::where('tenant_subscription_id', $subscription->id)
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->whereNotNull('metadata->addons')
            ->whereNull('metadata->addons_applied')
            ->get();

        foreach ($payments as $payment) {
            $previous = [];
            foreach ((array) ($payment->metadata['addons'] ?? []) as $line) {
                $row = TenantAddon::where('tenant_id', $subscription->tenant_id)->find((int) ($line['id'] ?? 0));
                if (! $row || ! in_array($row->status, [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_EXPIRED], true)) {
                    continue;
                }
                $pricedEnd = ! empty($line['row_ends_at']) ? Carbon::parse($line['row_ends_at']) : null;
                if ($pricedEnd && $row->ends_at && $row->ends_at->greaterThan($pricedEnd->copy()->addDay())) {
                    // Renewed on its own link after this payment was priced: this
                    // payment still bought one cycle — add it, don't swallow it.
                    $end = $this->addCycle($row->ends_at->copy(), (string) $row->billing_cycle);
                } else {
                    $end = $row->ends_at && $row->ends_at->greaterThan($subscription->ends_at) ? $row->ends_at->copy() : $subscription->ends_at->copy();
                }
                $previous[(string) $row->id] = ['prev' => $row->ends_at?->toIso8601String(), 'new' => $end->toIso8601String()];
                $meta = $row->metadata ?? [];
                unset($meta['reminded_for']);
                $meta['last_payment_id'] = $payment->id;
                $row->update(['status' => TenantAddon::STATUS_ACTIVE, 'ends_at' => $end, 'metadata' => $meta]);

                // A separate renewal link sent earlier is no longer needed.
                $link = $row->paymentLink;
                if ($link && $link->status === PaymentLink::STATUS_ACTIVE && ! $link->pendingOfflineAttempt()) {
                    $link->update(['status' => PaymentLink::STATUS_CANCELLED]);
                }
                $applied++;
            }

            $meta = $payment->metadata ?? [];
            $meta['addons_applied'] = now()->toIso8601String();
            // What each add-on's end was before — a refund of this payment
            // takes exactly the added time back (onPlanPaymentRefunded).
            $meta['addons_previous'] = $previous;
            $payment->metadata = $meta;
            $payment->saveQuietly();
        }

        return $applied;
    }

    /** Is this add-on renewed through the plan payment (same cycle, ends with the plan)? */
    public function billedWithPlan(TenantAddon $row, ?TenantSubscription $subscription): bool
    {
        return $row->isRecurring()
            && $row->source === TenantAddon::SOURCE_PURCHASE
            && $row->ends_at
            && $subscription
            && $subscription->status === TenantSubscription::STATUS_ACTIVE
            && $subscription->billing_cycle === $row->billing_cycle
            && $subscription->ends_at
            && abs($subscription->ends_at->getTimestamp() - $row->ends_at->getTimestamp()) <= 86400
            // Free plans are never paid for, so their add-ons renew on their own link.
            && $subscription->plan
            && $subscription->plan->getPriceForCycle((string) $row->billing_cycle) > 0;
    }

    // ── Admin actions ───────────────────────────────────────────────

    /** Give a tenant an add-on without payment, until a date (null = no end). */
    public function grant(Tenant $tenant, string $key, ?Carbon $until, ?string $note = null, int $quantity = 1): TenantAddon
    {
        if (! isset(Addon::catalog()[$key])) {
            throw new \DomainException(__('super.addons.err_unknown'));
        }
        $isPack = str_starts_with($key, Addon::LIMIT_PREFIX);
        $quantity = $isPack ? max(1, min(Addon::MAX_PACKS, $quantity)) : 1;

        // A new grant replaces whatever was running for that key (packs stack instead).
        if (! $isPack) {
            // revoke() also cancels any open renewal link, so it can't be paid afterwards.
            TenantAddon::where('tenant_id', $tenant->id)->where('addon_key', $key)
                ->whereIn('status', [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_PENDING])
                ->get()->each(fn (TenantAddon $old) => $this->revoke($old));
        }

        $packSize = $isPack ? (Addon::where('key', $key)->first()?->packSize() ?? 1) : 1;
        $attrs = [
            'tenant_id'     => $tenant->id,
            'addon_key'     => $key,
            'billing_cycle' => 'manual',
            'amount'        => 0,
            'currency'      => GeneralSetting::currencyCode(),
            'status'        => TenantAddon::STATUS_ACTIVE,
            'source'        => TenantAddon::SOURCE_ADMIN,
            'starts_at'     => now(),
            'ends_at'       => $until?->copy()->endOfDay(),
            'notes'         => $note,
            'metadata'      => $isPack ? ['units' => $quantity * $packSize] : null,
        ];
        if (Addon::extrasReady()) {
            $attrs['quantity'] = $quantity;
        }

        return TenantAddon::create($attrs);
    }

    public function revoke(TenantAddon $row): void
    {
        if ($row->status === TenantAddon::STATUS_PENDING) {
            $this->cancelPending($row);

            return;
        }

        $row->update([
            'status'       => TenantAddon::STATUS_REVOKED,
            'cancelled_at' => now(),
            'ends_at'      => $row->ends_at && $row->ends_at->isPast() ? $row->ends_at : now(),
        ]);

        // An open renewal link must not re-activate a revoked add-on.
        $link = $row->paymentLink;
        if ($link && $link->status === PaymentLink::STATUS_ACTIVE && ! $link->pendingOfflineAttempt()) {
            $link->update(['status' => PaymentLink::STATUS_CANCELLED]);
        }
    }

    // ── Scheduler ───────────────────────────────────────────────────

    /**
     * Daily: expire lapsed add-ons, send renewal links before the end date
     * (not for add-ons billed with the plan), remind trials before they end,
     * drop purchases nobody paid, and re-apply plan payments missed by the
     * subscription hook.
     *
     * @return array{expired: int, reminded: int, trial_reminded: int, abandoned: int, plan_applied: int}
     */
    public function sweep(): array
    {
        $stats = ['expired' => 0, 'reminded' => 0, 'trial_reminded' => 0, 'abandoned' => 0, 'plan_applied' => 0];
        if (! Addon::tablesReady()) {
            return $stats;
        }

        // Plan payments with add-on lines whose subscription hook did not apply them.
        TenantBillingPayment::where('status', TenantBillingPayment::STATUS_PAID)
            ->whereNotNull('metadata->addons')->whereNull('metadata->addons_applied')
            ->where('paid_at', '>=', now()->subDays(45))
            ->with('subscription')->get()
            ->each(function (TenantBillingPayment $p) use (&$stats) {
                if ($p->subscription) {
                    $stats['plan_applied'] += $this->applyPlanPayments($p->subscription);
                }
            });

        TenantAddon::where('status', TenantAddon::STATUS_ACTIVE)
            ->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->each(function (TenantAddon $row) use (&$stats) {
                $row->update(['status' => TenantAddon::STATUS_EXPIRED]);
                $stats['expired']++;
            });

        TenantAddon::where('status', TenantAddon::STATUS_ACTIVE)
            ->whereIn('billing_cycle', ['monthly', 'yearly'])
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(self::REMINDER_DAYS)])
            ->each(function (TenantAddon $row) use (&$stats) {
                $key = $row->ends_at->toDateString();
                if (($row->metadata['reminded_for'] ?? null) === $key) {
                    return;
                }
                // Renewed together with the plan: the plan's own reminder covers it.
                if ($row->tenant && $this->billedWithPlan($row, $this->grantingSubscription($row->tenant))) {
                    return;
                }
                // Nothing to renew any more (plan now includes it, no longer
                // offers it, or a newer copy runs): no email, no link.
                $addon = Addon::where('key', $row->addon_key)->first();
                if (! $row->tenant || ! $addon || $this->renewalBlocker($row, $addon) !== null) {
                    return;
                }
                try {
                    $link = $this->startRenewal($row);
                    $email = (string) ($row->tenant?->admin_email ?? '');
                    if ($email !== '') {
                        app(PaymentLinkNotifier::class)->sendEmail($link, $email);
                    }
                    $row->update(['metadata' => array_merge($row->metadata ?? [], ['reminded_for' => $key])]);
                    $stats['reminded']++;
                } catch (\Throwable $e) {
                    Log::warning("AddonService: renewal reminder for tenant add-on {$row->id} failed: {$e->getMessage()}");
                }
            });

        TenantAddon::where('status', TenantAddon::STATUS_ACTIVE)
            ->where('billing_cycle', TenantAddon::CYCLE_TRIAL)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(self::TRIAL_REMINDER_DAYS)])
            ->each(function (TenantAddon $row) use (&$stats) {
                if (! empty($row->metadata['trial_reminded'])) {
                    return;
                }
                $this->notifyTrialEnding($row);
                $row->update(['metadata' => array_merge($row->metadata ?? [], ['trial_reminded' => now()->toIso8601String()])]);
                $stats['trial_reminded']++;
            });

        TenantAddon::where('status', TenantAddon::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays(self::LINK_DAYS + 1))
            ->each(function (TenantAddon $row) use (&$stats) {
                $link = $row->paymentLink;
                if ($link && $link->pendingOfflineAttempt()) {
                    return; // a bank transfer is waiting for review
                }
                $this->cancelPending($row);
                $stats['abandoned']++;
            });

        return $stats;
    }

    // ── Internals ───────────────────────────────────────────────────

    public function grantingSubscription(Tenant $tenant): ?TenantSubscription
    {
        return TenantSubscription::where('tenant_id', $tenant->id)
            ->grantingAccess()->with('plan')->latest()->first();
    }

    /** Keys this tenant has right now: its plan's features plus active add-ons (as a set). */
    protected function effectiveKeys(Tenant $tenant, ?Plan $plan): array
    {
        $keys = array_flip(TenantAddon::activeKeysFor((string) $tenant->id));
        foreach (Addon::catalog() as $key => $meta) {
            if ($this->planIncludes($plan, $key)) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** Shared purchase / trial checks: a plan, offered to it, not included, requirements met. */
    protected function assertCanAcquire(Tenant $tenant, Addon $addon, ?TenantSubscription $subscription): void
    {
        if (! $subscription || ! $subscription->plan) {
            throw new \DomainException(__('super.addons.err_no_plan'));
        }
        if (! $addon->offeredToPlan($subscription->plan)) {
            throw new \DomainException(__('super.addons.err_not_for_plan'));
        }
        if ($this->planIncludes($subscription->plan, $addon->key)) {
            throw new \DomainException(__('super.addons.err_included'));
        }

        $missing = [];
        $have = $this->effectiveKeys($tenant, $subscription->plan);
        $catalog = Addon::catalog();
        foreach ($addon->requiredKeys() as $req) {
            if (! isset($have[$req])) {
                $missing[] = $catalog[$req]['label'] ?? $req;
            }
        }
        if ($missing) {
            throw new \DomainException(__('super.addons.err_requires', ['names' => implode(', ', $missing)]));
        }
    }

    protected function alignable(?TenantSubscription $subscription, string $cycle, Carbon $from): bool
    {
        return $subscription
            && $subscription->status === TenantSubscription::STATUS_ACTIVE
            && $subscription->billing_cycle === $cycle
            && $subscription->ends_at
            && $subscription->ends_at->greaterThan($from);
    }

    protected function addCycle(Carbon $from, string $cycle): Carbon
    {
        return match ($cycle) {
            'yearly' => $from->addYearNoOverflow(),
            default  => $from->addMonthNoOverflow(),
        };
    }

    protected function cancelPending(TenantAddon $row): void
    {
        $row->update(['status' => TenantAddon::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $link = $row->paymentLink;
        if ($link && $link->status === PaymentLink::STATUS_ACTIVE && ! $link->pendingOfflineAttempt()) {
            $link->update(['status' => PaymentLink::STATUS_CANCELLED]);
        }
    }

    protected function createLink(Tenant $tenant, TenantAddon $row, Addon $addon, float $amount, ?Carbon $until, bool $renewal = false): PaymentLink
    {
        $quantity = (int) ($row->quantity ?? 1);
        $name = $addon->displayName() . ($quantity > 1 ? ' × ' . $quantity : '');
        $title = __($renewal ? 'super.addons.link_title_renewal' : 'super.addons.link_title', [
            'name'  => $name,
            'cycle' => __('super.addons.cycle_' . $row->billing_cycle),
        ]);
        $description = $until
            ? __('super.addons.link_until', ['date' => $until->format('M d, Y')])
            : __('super.addons.link_lifetime');

        // A prorated purchase is priced up to $until — it must not be payable
        // after that date (it would buy a period that already ended).
        $expires = now()->addDays(self::LINK_DAYS);
        if (! $renewal && ! empty($row->metadata['aligned']) && $until && $until->lessThan($expires)) {
            $expires = $until->copy();
        }

        return PaymentLink::create([
            'tenant_id'       => $tenant->id,
            'title'           => Str::limit($title, 190, ''),
            'description'     => $description,
            'amount'          => $amount,
            // The add-on row's currency = the workspace's billing currency.
            'currency'        => strtoupper((string) ($row->currency ?: GeneralSetting::currencyCode())),
            'status'          => PaymentLink::STATUS_ACTIVE,
            'expires_at'      => $expires,
            'tenant_addon_id' => $row->id,
        ]);
    }

    protected function notifyActivated(TenantAddon $row): void
    {
        $until = $row->ends_at ? $row->ends_at->format('M d, Y') : __('super.addons.no_end');
        $this->mailTenant($row, __('super.addons.mail_activated_subject', ['name' => $row->displayName()]),
            __('super.addons.mail_activated_body', ['name' => $row->displayName(), 'until' => $until]));
    }

    protected function notifyTrialEnding(TenantAddon $row): void
    {
        $url = $row->tenant ? rtrim($row->tenant->getTenantUrl(), '/') . '/subscription/addons' : '';
        $this->mailTenant($row, __('super.addons.mail_trial_subject', ['name' => $row->displayName()]),
            __('super.addons.mail_trial_body', [
                'name' => $row->displayName(),
                'date' => $row->ends_at?->format('M d, Y') ?? '',
                'url'  => $url,
            ]));
    }

    protected function mailTenant(TenantAddon $row, string $subject, string $body): void
    {
        try {
            $email = (string) ($row->tenant?->admin_email ?? '');
            if ($email === '') {
                return;
            }
            \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($email)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning("AddonService: email for tenant add-on {$row->id} failed: {$e->getMessage()}");
        }
    }
}
