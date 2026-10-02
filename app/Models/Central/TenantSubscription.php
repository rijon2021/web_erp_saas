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
    public function scopeGrantingAccess($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
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
        });
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
     * Activate after successful payment (first-time activation).
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
    }

    /**
     * Extend an already-active subscription by one billing cycle (renewal).
     * If ends_at is still in the future, extends from that date.
     * If ends_at has passed, extends from now.
     */
    public function renew(): void
    {
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
