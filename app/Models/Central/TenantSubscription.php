<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Central (SaaS) tenant subscription to a plan — not tenant-scoped.
 */
class TenantSubscription extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_subscriptions';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_TRIAL     = 'trial';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Allowed status transitions. Keys are the source status; values are the
     * statuses that source can move to. Used by transitionTo() to enforce a
     * single chokepoint for admin-driven status changes.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING   => [self::STATUS_ACTIVE, self::STATUS_TRIAL, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_TRIAL     => [self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_EXPIRED],
        self::STATUS_ACTIVE    => [self::STATUS_CANCELLED, self::STATUS_SUSPENDED, self::STATUS_EXPIRED],
        self::STATUS_CANCELLED => [self::STATUS_ACTIVE],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_EXPIRED   => [self::STATUS_ACTIVE],
        self::STATUS_FAILED    => [self::STATUS_PENDING, self::STATUS_ACTIVE],
    ];

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'billing_cycle',
        'amount',
        'currency',
        'status',
        'starts_at',
        'trial_ends_at',
        'ends_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'starts_at'     => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at'       => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn (self $sub) => static::bumpTenantVersion($sub->tenant_id));
        static::deleted(fn (self $sub) => static::bumpTenantVersion($sub->tenant_id));

        // Activated / renewed: add-ons paid on this subscription's plan payment
        // (AddonService::planRenewalLines) follow it to its new end date.
        static::saved(function (self $sub) {
            if ($sub->status !== self::STATUS_ACTIVE || ! $sub->ends_at
                || ! ($sub->wasRecentlyCreated || $sub->wasChanged('ends_at') || $sub->wasChanged('status'))) {
                return;
            }
            try {
                app(\App\Services\Billing\AddonService::class)->applyPlanPayments($sub);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Add-ons: applying plan payment for subscription {$sub->id} failed: {$e->getMessage()}");
            }
        });
    }

    /**
     * Move the tenant row's updated_at forward on any subscription change.
     *
     * EnsureActiveSubscription keys its cached grant on that timestamp. The
     * cache lives under the tenant's prefix, out of reach of the super admin
     * (central) side, so instead of deleting the entry the key itself changes:
     * the tenant row is read fresh on every request, and the very next one
     * misses the cache and re-checks. GREATEST(…, +1s) keeps the value strictly
     * increasing even for two changes inside the same second. A plain query —
     * no Tenant model events, no rewrite of its virtual `data` column.
     */
    public static function bumpTenantVersion($tenantId): void
    {
        if (! $tenantId) {
            return;
        }

        $central = \DB::connection('central');
        $table = $central->getQueryGrammar()->wrapTable((new Tenant)->getTable());
        $now = now()->toDateTimeString();

        $central->update(
            "UPDATE {$table} SET updated_at = GREATEST(?, COALESCE(updated_at + INTERVAL 1 SECOND, ?)) WHERE id = ?",
            [$now, $now, $tenantId]
        );
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(TenantBillingPayment::class, 'tenant_subscription_id');
    }

    /**
     * Statuses that can lapse purely through the passage of time (trial window
     * or paid period running out). Everything else changes only by an explicit
     * action (payment, admin, cancellation).
     */
    public const LAPSABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TRIAL,
        self::STATUS_CANCELLED,
    ];

    /**
     * Subscriptions that currently entitle the tenant to their plan, judged by
     * DATE at query time — the SQL twin of grantsAccess():
     *   - active rows with no end date or an end date in the future,
     *   - trial rows whose trial window is still open,
     *   - cancelled rows whose paid period has not run out yet.
     *
     * The status column alone is never trusted: it is only flipped to
     * "expired" by the daily cron or lazily by the access middleware, and a
     * host without cron must still lock a lapsed trial out everywhere this
     * scope is used (plan features, storefront, portal, Zapier).
     *
     * A cancelled row only counts while ends_at is still in the future — the
     * same condition isCancelled() applies. Without the date check a
     * subscription cancelled while it was still pending (never paid for, so
     * ends_at is null) would resolve as the tenant's current plan and hand
     * them its features for free.
     */
    public function scopeGrantingAccess($query, bool $withGrace = true)
    {
        $now = now();

        return $query->where(function ($q) use ($now, $withGrace) {
            $q->where(function ($active) use ($now) {
                $active->where('status', self::STATUS_ACTIVE)
                       ->where(function ($period) use ($now) {
                           $period->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                       });
            })->orWhere(function ($trial) use ($now) {
                $trial->where('status', self::STATUS_TRIAL)
                      ->whereNotNull('trial_ends_at')
                      ->where('trial_ends_at', '>', $now);
            })->orWhere(function ($cancelled) use ($now) {
                $cancelled->where('status', self::STATUS_CANCELLED)
                          ->whereNotNull('ends_at')
                          ->where('ends_at', '>', $now);
            });

            $graceDays = $withGrace ? static::graceDays() : 0;
            if ($graceDays > 0) {
                $q->orWhere(fn ($grace) => static::applyGraceWindow($grace, $now, $graceDays));
            }
        });
    }

    /**
     * Rows whose paid period ended less than the grace days ago — SQL twin of
     * inGracePeriod(). Empty when grace is off.
     */
    public function scopeInGrace($query)
    {
        $graceDays = static::graceDays();
        if ($graceDays <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($grace) => static::applyGraceWindow($grace, now(), $graceDays));
    }

    /**
     * A grace row is a lapsed PAID period: no trial behind it, not cancelled by
     * the customer, and either still labelled active or already flipped to
     * expired by the cron / access middleware.
     */
    protected static function applyGraceWindow($query, $now, int $graceDays): void
    {
        $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_EXPIRED])
              ->whereNull('trial_ends_at')
              ->whereNull('cancelled_at')
              ->whereNotNull('ends_at')
              ->where('ends_at', '<=', $now)
              ->where('ends_at', '>', $now->copy()->subDays($graceDays));
    }

    /** Memoised grace setting (read on every access check). */
    protected static array $graceDaysMemo = [0, 0];

    public static function graceDays(): int
    {
        [$value, $until] = static::$graceDaysMemo;
        if ($until > time()) {
            return $value;
        }

        try {
            $value = GeneralSetting::instance()->subscriptionGraceDays();
        } catch (\Throwable) {
            $value = 0;
        }
        static::$graceDaysMemo = [$value, time() + 60];

        return $value;
    }

    /** Forget the memoised grace setting (after the super admin saves it). */
    public static function flushGraceDays(): void
    {
        static::$graceDaysMemo = [0, 0];
    }

    /** PHP twin of scopeInGrace(). */
    public function inGracePeriod(): bool
    {
        $graceDays = static::graceDays();

        return $graceDays > 0
            && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_EXPIRED], true)
            && $this->trial_ends_at === null
            && $this->cancelled_at === null
            && $this->ends_at !== null
            && $this->ends_at->isPast()
            && $this->ends_at->copy()->addDays($graceDays)->isFuture();
    }

    /** When the grace period of this row runs out (null when it has none). */
    public function graceEndsAt(): ?\Carbon\CarbonInterface
    {
        $graceDays = static::graceDays();

        return ($graceDays > 0 && $this->ends_at) ? $this->ends_at->copy()->addDays($graceDays) : null;
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return !$this->ends_at || $this->ends_at->isFuture();
        }

        return $this->isOnTrial();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED && $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Does this row entitle the tenant to use the workspace right now?
     * Single source of truth for every access decision (PHP twin of
     * scopeGrantingAccess()). Date-based — never depends on the cron having
     * flipped the status.
     */
    public function grantsAccess(): bool
    {
        return $this->isActive() || $this->isCancelled();
    }

    /**
     * When the access this row grants right now runs out: the latest of the
     * windows that grantsAccess() accepted, or null when it is open-ended (an
     * active row with no end date). Only meaningful while grantsAccess() is
     * true; used to cap the access middleware's cached verdict so a lapse
     * still locks the tenant out at the exact moment.
     */
    public function accessEndsAt(): ?\Carbon\CarbonInterface
    {
        $ends = [];

        if ($this->status === self::STATUS_ACTIVE && (! $this->ends_at || $this->ends_at->isFuture())) {
            if (! $this->ends_at) {
                return null;
            }
            $ends[] = $this->ends_at;
        }
        if ($this->isOnTrial()) {
            $ends[] = $this->trial_ends_at;
        }
        if ($this->isCancelled()) {
            $ends[] = $this->ends_at;
        }

        return empty($ends) ? now() : max($ends);
    }

    /**
     * A lapsable row whose window has closed but whose status label has not
     * caught up yet (cron not run / not installed).
     */
    public function hasLapsed(): bool
    {
        return match ($this->status) {
            self::STATUS_TRIAL     => $this->trial_ends_at !== null && $this->trial_ends_at->isPast(),
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED => $this->ends_at !== null && $this->ends_at->isPast(),
            default                => false,
        };
    }

    /**
     * Flip a lapsed row to "expired" on the spot so the admin panel, counts
     * and reports reflect reality without waiting for the daily cron.
     * Returns true when a change was written.
     */
    public function expireIfLapsed(): bool
    {
        if (! $this->hasLapsed()) {
            return false;
        }

        $this->update(['status' => self::STATUS_EXPIRED]);

        return true;
    }

    /**
     * Bulk twin of expireIfLapsed(): mark every lapsed row as expired, for one
     * tenant or platform-wide. Cheap enough to run before list screens.
     * Returns the number of rows changed.
     */
    public static function expireLapsed(?string $tenantId = null): int
    {
        $now  = now();
        $base = static::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId));

        $changed  = (clone $base)->where('status', self::STATUS_TRIAL)
            ->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', $now)
            ->update(['status' => self::STATUS_EXPIRED, 'updated_at' => $now]);
        $changed += (clone $base)->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_CANCELLED])
            ->whereNotNull('ends_at')->where('ends_at', '<=', $now)
            ->update(['status' => self::STATUS_EXPIRED, 'updated_at' => $now]);

        return (int) $changed;
    }

    public function canResume(): bool
    {
        return $this->isCancelled();
    }

    public function isExpired(): bool
    {
        return $this->ends_at && $this->ends_at->isPast() && $this->status !== self::STATUS_ACTIVE;
    }

    public function daysRemaining(): int
    {
        if (!$this->ends_at) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->ends_at, false));
    }

    /**
     * A tenant asks to switch to a free plan (Blade billing and the SPA).
     *
     * Deliberately NOT instant: the request goes through the same
     * pending → admin-approval flow as paid plans. Instant activation let an
     * expired tenant restore permanent access (ends_at = null) with one
     * click, silently overwriting their expired paid subscription row.
     * Reuses an existing pending request rather than stacking a second one.
     */
    public static function requestFreePlan(string $tenantId, Plan $plan, string $cycle): ?self
    {
        $existingPending = static::where('tenant_id', $tenantId)
            ->where('status', self::STATUS_PENDING)
            ->latest()
            ->first();

        // A paid request with a payment still on its way (bank proof under
        // review, open gateway checkout) must not be rewritten into a free
        // one — approving that payment would then activate the free plan.
        // Null = refused; the caller tells the user to wait or cancel it.
        if ($existingPending && $existingPending->payments()->where('status', TenantBillingPayment::STATUS_PENDING)->exists()) {
            return null;
        }

        if ($existingPending) {
            $existingPending->update([
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => 0,
                'currency'      => GeneralSetting::currencyCode(),
                'starts_at'     => $existingPending->starts_at ?? now(),
            ]);

            return $existingPending;
        }

        return static::create([
            'tenant_id'     => $tenantId,
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'amount'        => 0,
            'currency'      => GeneralSetting::currencyCode(),
            'status'        => self::STATUS_PENDING,
            'starts_at'     => now(),
        ]);
    }

    /**
     * A refunded period's time may already have been carried over to a newer
     * subscription (renewal / plan change through activate()). Take that time
     * back, so a refund cannot leave the customer with the paid days anyway.
     * Uses the `credited` change-log entries (no-op until that table exists).
     */
    public static function revokeCarriedTime(self $refunded): void
    {
        if (! SubscriptionEvent::available()) {
            return;
        }

        $events = SubscriptionEvent::where('tenant_id', $refunded->tenant_id)
            ->where('action', SubscriptionEvent::ACTION_CREDITED)
            ->get()
            ->filter(fn (SubscriptionEvent $e) => (int) ($e->details['from_subscription_id'] ?? 0) === (int) $refunded->id
                && ! ($e->details['revoked'] ?? false));

        foreach ($events as $event) {
            $target = static::find($event->tenant_subscription_id);
            $seconds = (int) ($event->details['seconds'] ?? 0);
            if (! $target || $seconds <= 0 || ! $target->ends_at) {
                continue;
            }

            $newEnd = $target->ends_at->copy()->subSeconds($seconds);
            $target->update(['ends_at' => $newEnd->lt(now()) ? now() : $newEnd]);
            $event->update(['details' => array_merge($event->details, ['revoked' => true])]);
        }
    }

    /** Most time a plan change can carry over to the new plan. */
    public const MAX_CARRY_OVER_DAYS = 365;

    /**
     * Activate after successful payment (first activation, plan change or
     * early renewal through checkout).
     *
     * Paid time left on the subscription being replaced is never lost:
     *  - same plan (early renewal, monthly ↔ yearly): the new period starts
     *    when the current one ends;
     *  - another plan: the unused value is converted into extra days at the
     *    new plan's price (capped at MAX_CARRY_OVER_DAYS).
     */
    public function activate(): void
    {
        $startsAt = now();
        // Free plans don't expire; paid plans run one billing cycle.
        $endsAt = $this->plan?->isFree()
            ? null
            : ($this->billing_cycle === 'yearly'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth());

        // Paying on THIS row while it still runs (a trial, or a cancellation
        // still inside its paid period — e.g. an admin-recorded payment): the
        // new period starts when the current one ends. Other statuses that
        // reach here (pending, expired, failed…) have no time of their own.
        $ownCarry = null;
        $ownEnd = $this->status === self::STATUS_TRIAL ? $this->trial_ends_at
            : ($this->status === self::STATUS_CANCELLED ? $this->ends_at : null);
        if ($endsAt && $ownEnd && $ownEnd->isFuture()) {
            $remaining = round(($ownEnd->getTimestamp() - $startsAt->getTimestamp()) / 86400, 1);
            $endsAt = $this->billing_cycle === 'yearly' ? $ownEnd->copy()->addYear() : $ownEnd->copy()->addMonth();
            $ownCarry = ['type' => $this->status === self::STATUS_TRIAL ? 'trial' : 'renewal', 'days' => $remaining, 'ends_at' => $endsAt->toDateTimeString()];
        }

        $previous = ($endsAt && ! $ownCarry) ? $this->periodToCarryOver() : null;
        $carry = $previous ? static::carryOver($previous, $this, $endsAt) : null;
        if ($carry) {
            $endsAt = $carry['ends_at'];
        }

        $this->update([
            'status'        => self::STATUS_ACTIVE,
            'starts_at'     => $startsAt,
            'trial_ends_at' => null,
            'ends_at'       => $endsAt,
            'cancelled_at'  => null,
        ]);

        // This subscription replaces whatever the tenant was on before (free
        // trial, previous plan) — expire those rows so only one is live.
        static::where('tenant_id', $this->tenant_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIAL])
            ->update(['status' => self::STATUS_EXPIRED]);

        if ($carry) {
            // Its remaining time now lives on this row — a cancelled one too.
            // Ended now: its time has moved, so it must not later look like a
            // period that "just ran out" (grace window, churn).
            static::whereKey($previous->getKey())->update(array_filter([
                'status'        => self::STATUS_EXPIRED,
                'ends_at'       => now(),
                'trial_ends_at' => $previous->trial_ends_at ? now() : null,
            ], fn ($v) => $v !== null));
            SubscriptionEvent::record($this, SubscriptionEvent::ACTION_CREDITED, $carry['details']);
        }
        if ($ownCarry) {
            SubscriptionEvent::record($this, SubscriptionEvent::ACTION_CREDITED, $ownCarry);
        }

        Tenant::liftNonPaymentSuspension($this->tenant_id);
    }

    /**
     * The tenant's other period that still has time to carry over: a PAID
     * one (active, or cancelled but inside its period), or a running trial of
     * this same plan (paying during a trial keeps the trial days). Free plans
     * and trials of another plan carry nothing.
     */
    protected function periodToCarryOver(): ?self
    {
        $now = now();

        return static::where('tenant_id', $this->tenant_id)
            ->whereKeyNot($this->getKey())
            ->where(function ($q) use ($now) {
                $q->where(fn ($paid) => $paid->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_CANCELLED])
                        ->whereNull('trial_ends_at')
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '>', $now)
                        ->where('amount', '>', 0)
                        // Refunded time is not the customer's to carry.
                        ->whereDoesntHave('payments', fn ($p) => $p->where('status', TenantBillingPayment::STATUS_REFUNDED)))
                  ->orWhere(fn ($trial) => $trial->where('status', self::STATUS_TRIAL)
                        ->where('plan_id', $this->plan_id)
                        ->where('trial_ends_at', '>', $now));
            })
            ->latest('id')
            ->first();
    }

    /**
     * Where $next's period should end once $previous's unused time is carried
     * over (pure — no writes). Null when there is nothing to carry.
     *
     * @return array{ends_at: \Carbon\CarbonInterface, details: array}|null
     */
    public static function carryOver(self $previous, self $next, \Carbon\CarbonInterface $plainEndsAt): ?array
    {
        $now = now();
        $isTrial = $previous->trial_ends_at !== null;
        $previousEnd = $isTrial ? $previous->trial_ends_at : $previous->ends_at;
        if (! $previousEnd || $previousEnd->lte($now)) {
            return null;
        }

        $remainingSeconds = $previousEnd->getTimestamp() - $now->getTimestamp();

        if ((int) $previous->plan_id === (int) $next->plan_id) {
            // Same plan (or its trial): stack the new period after the current one.
            $endsAt = $next->billing_cycle === 'yearly'
                ? $previousEnd->copy()->addYear()
                : $previousEnd->copy()->addMonth();

            return [
                'ends_at' => $endsAt,
                'details' => [
                    'type'          => $isTrial ? 'trial' : 'renewal',
                    'from_subscription_id' => $previous->id,
                    'seconds'       => $remainingSeconds,
                    'from_ends_at'  => $previousEnd->toDateTimeString(),
                    'days'          => round($remainingSeconds / 86400, 1),
                    'ends_at'       => $endsAt->toDateTimeString(),
                ],
            ];
        }

        if ($isTrial) {
            return null; // a trial of another plan was free — nothing to credit
        }

        // Another plan: value per second of each plan at its nominal cycle.
        $cycleSeconds = fn (?string $cycle) => ($cycle === 'yearly' ? 365 : 30) * 86400;
        $oldRate = (float) $previous->amount / $cycleSeconds($previous->billing_cycle);
        $newRate = (float) $next->amount / $cycleSeconds($next->billing_cycle);
        if ($oldRate <= 0 || $newRate <= 0) {
            return null;
        }

        $creditValue = $oldRate * $remainingSeconds;
        $extraSeconds = (int) min($creditValue / $newRate, self::MAX_CARRY_OVER_DAYS * 86400);
        if ($extraSeconds < 3600) {
            return null;
        }

        $endsAt = $plainEndsAt->copy()->addSeconds($extraSeconds);

        return [
            'ends_at' => $endsAt,
            'details' => [
                'type'         => 'plan_change',
                'from_subscription_id' => $previous->id,
                'seconds'      => $extraSeconds,
                'from_plan_id' => $previous->plan_id,
                'credit'       => round($creditValue, 2),
                'days'         => round($extraSeconds / 86400, 1),
                'ends_at'      => $endsAt->toDateTimeString(),
            ],
        ];
    }

    /**
     * Extend an already-active subscription by one billing cycle (renewal).
     * If ends_at is still in the future, extends from that date.
     * If ends_at has passed, extends from now.
     */
    public function renew(): void
    {
        // A free plan never expires — a payment recorded against it must not
        // give it an end date.
        if ($this->plan?->isFree() && $this->ends_at === null) {
            $this->update(['status' => self::STATUS_ACTIVE, 'trial_ends_at' => null, 'cancelled_at' => null]);
            Tenant::liftNonPaymentSuspension($this->tenant_id);

            return;
        }

        $base = ($this->ends_at && $this->ends_at->isFuture())
            ? $this->ends_at->copy()
            : now();

        $newEndsAt = $this->billing_cycle === 'yearly'
            ? $base->addYear()
            : $base->addMonth();

        $this->update([
            'status'        => self::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'ends_at'       => $newEndsAt,
            'cancelled_at'  => null,
        ]);

        Tenant::liftNonPaymentSuspension($this->tenant_id);
    }

    /**
     * Grant free days (super-admin "Extend"). A trial row extends its trial;
     * otherwise the paid period grows from its end date, or from today when it
     * already ended. A lapsed row becomes active again; a customer-cancelled
     * row still inside its period stays cancelled and simply ends later.
     * Returns false when there is nothing to extend (open-ended row).
     */
    public function extendByDays(int $days): bool
    {
        if ($this->trial_ends_at !== null) {
            // Trial rows mirror the trial end in ends_at.
            $base = $this->trial_ends_at->isFuture() ? $this->trial_ends_at->copy() : now();
            $newEnd = $base->addDays($days);
            $this->update([
                'status'        => $this->status === self::STATUS_SUSPENDED ? self::STATUS_SUSPENDED : self::STATUS_TRIAL,
                'trial_ends_at' => $newEnd,
                'ends_at'       => $newEnd,
            ]);

            return true;
        }

        if ($this->ends_at === null) {
            return false;
        }

        $base = $this->ends_at->isFuture() ? $this->ends_at->copy() : now();
        $attributes = ['ends_at' => $base->addDays($days)];

        // An admin-suspended subscription stays suspended (only its dates move):
        // lifting a suspension is a separate, deliberate action.
        $keepsCancelled = $this->status === self::STATUS_CANCELLED && $this->ends_at->isFuture();
        if (! $keepsCancelled && ! in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
            $attributes['status'] = self::STATUS_ACTIVE;
            $attributes['cancelled_at'] = null;
        }

        $this->update($attributes);

        return true;
    }

    /**
     * Move this row to another plan / billing cycle (super-admin "Change
     * plan"). A free plan never expires. Otherwise $restart starts a full new
     * period today; moving off an open-ended (free) row always restarts, or
     * the paid plan would never expire either. Returns the attributes written.
     */
    public function switchPlan(Plan $plan, string $cycle, float $amount, bool $restart): array
    {
        $attributes = [
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'amount'        => $amount,
        ];

        $wasOpenEnded = $this->ends_at === null && $this->trial_ends_at === null;

        if ($plan->isFree()) {
            $attributes += [
                'status'        => self::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'ends_at'       => null,
                'cancelled_at'  => null,
            ];
        } elseif ($restart || $wasOpenEnded) {
            $now = now();
            $attributes += [
                'status'        => self::STATUS_ACTIVE,
                'starts_at'     => $now,
                'trial_ends_at' => null,
                'ends_at'       => $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth(),
                'cancelled_at'  => null,
            ];
        }

        $this->update($attributes);

        return $attributes;
    }

    /**
     * Re-anchor the signup subscription to the moment the workspace goes live.
     *
     * A signup can wait for approval (or a failed provisioning) for days; the
     * customer had no access during that time, so the trial / paid period is
     * shifted forward by the delay — they always get the full length they
     * signed up or paid for. A pending (or lapsed-while-waiting) row becomes
     * trial or active. Call only when the workspace goes live the first time.
     */
    public function startAtGoLive(): void
    {
        $now = now();
        $start = $this->starts_at ?? $this->created_at;
        $delay = $start ? max(0, $now->getTimestamp() - $start->getTimestamp()) : 0;

        $attributes = ['starts_at' => $now];
        if ($delay > 0) {
            if ($this->trial_ends_at) {
                $attributes['trial_ends_at'] = $this->trial_ends_at->copy()->addSeconds($delay);
            }
            if ($this->ends_at) {
                $attributes['ends_at'] = $this->ends_at->copy()->addSeconds($delay);
            }
        }
        if (in_array($this->status, [self::STATUS_PENDING, self::STATUS_EXPIRED], true)) {
            $attributes['status'] = $this->trial_ends_at ? self::STATUS_TRIAL : self::STATUS_ACTIVE;
        }

        $this->update($attributes);
    }

    /**
     * Whether this subscription never went live: still pending (or failed)
     * with no billing period and no trial behind it. Typically a plan change
     * whose payment was never completed.
     */
    public function wasNeverActivated(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true)
            && ! $this->ends_at
            && ! $this->trial_ends_at;
    }

    /**
     * The status a cancellation should actually land on.
     *
     * "Cancelled" means "paid for, keep access until ends_at". A subscription
     * that was never activated has no such period to honour, so it goes to
     * "failed" instead — leaving it as a cancelled row with a null ends_at
     * would make it the newest row matching grantingAccess() and silently
     * promote the tenant onto the plan they never paid for.
     */
    public function cancellationStatus(): string
    {
        return $this->wasNeverActivated() ? self::STATUS_FAILED : self::STATUS_CANCELLED;
    }

    /**
     * Cancel subscription — remains active until ends_at.
     */
    public function cancel(): void
    {
        $this->update([
            'status'       => $this->cancellationStatus(),
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Resume a cancelled subscription (only if billing period hasn't expired).
     */
    public function resume(): void
    {
        if (! $this->canResume()) {
            return;
        }

        $this->update([
            'status'       => self::STATUS_ACTIVE,
            'cancelled_at' => null,
        ]);
    }

    /**
     * Whether moving from the current status to $newStatus is allowed.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->status === $newStatus) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * Apply a status transition with extra attributes. Returns false if the
     * transition is not allowed; the caller is responsible for surfacing the
     * error to the user.
     *
     * Resume of a cancelled subscription (cancelled → active) requires that
     * ends_at is still in the future, mirroring canResume().
     */
    public function transitionTo(string $newStatus, array $extra = []): bool
    {
        if (! $this->canTransitionTo($newStatus)) {
            return false;
        }

        if ($this->status === self::STATUS_CANCELLED && $newStatus === self::STATUS_ACTIVE) {
            if (! $this->ends_at || $this->ends_at->isPast()) {
                return false;
            }
            $extra['cancelled_at'] = null;
        }

        // Cancelling goes through cancellationStatus() here too, so the admin
        // edit form cannot produce a cancelled row that cancel() would have
        // downgraded to failed.
        if ($newStatus === self::STATUS_CANCELLED) {
            // Decide against the values this transition is about to write, so
            // an admin who sets an end date and cancels in the same edit still
            // gets a real cancellation.
            $newStatus = (clone $this)->fill($extra)->cancellationStatus();
            $extra['cancelled_at'] = $extra['cancelled_at'] ?? now();
        }

        $this->update(array_merge($extra, ['status' => $newStatus]));

        return true;
    }
}
