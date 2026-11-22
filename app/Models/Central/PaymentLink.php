<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A custom-amount payment request the super admin sends to one tenant.
 *
 * The tenant pays on the public /pay/{token} page; every attempt there is a
 * TenantBillingPayment carrying payment_link_id. When any of those attempts
 * becomes paid (webhook, return leg, cron, or admin "mark paid"),
 * TenantBillingPayment's updated hook calls markPaidFrom() on this link.
 */
class PaymentLink extends Model
{
    protected $connection = 'central';

    protected $table = 'payment_links';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    // The payment that settled the link was refunded.
    public const STATUS_REFUNDED  = 'refunded';
    // Derived only — never stored. An active link past expires_at.
    public const STATUS_EXPIRED   = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_REFUNDED,
    ];

    protected $fillable = [
        'token',
        'tenant_id',
        'title',
        'description',
        'amount',
        'currency',
        'status',
        'expires_at',
        'paid_at',
        'tenant_billing_payment_id',
        'created_by',
        'last_sent_at',
        'send_log',
        'tenant_addon_id',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'expires_at'   => 'datetime',
        'paid_at'      => 'datetime',
        'last_sent_at' => 'datetime',
        'send_log'     => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $link) {
            if (empty($link->token)) {
                $link->token = static::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    // ── Relations ────────────────────────────────────────────────────

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /** The attempt that settled the link. */
    public function payment()
    {
        return $this->belongsTo(TenantBillingPayment::class, 'tenant_billing_payment_id');
    }

    /** Every checkout attempt made from the link (paid, pending, failed, superseded). */
    public function attempts()
    {
        return $this->hasMany(TenantBillingPayment::class, 'payment_link_id');
    }

    public function creator()
    {
        return $this->belongsTo(CentralUser::class, 'created_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    /** Filter by the status shown in the UI, including the derived "expired". */
    public function scopeWithEffectiveStatus($query, string $status)
    {
        return match ($status) {
            self::STATUS_ACTIVE => $query->where('status', self::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            self::STATUS_EXPIRED => $query->where('status', self::STATUS_ACTIVE)
                ->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            default => $query->where('status', $status),
        };
    }

    // ── State ────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** True when the tenant may start a new checkout attempt. */
    public function isPayable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->isExpired() ? self::STATUS_EXPIRED : $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->effective_status) {
            self::STATUS_PAID      => 'success',
            self::STATUS_ACTIVE    => 'primary',
            self::STATUS_EXPIRED   => 'warning',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_REFUNDED  => 'info',
            default                => 'secondary',
        };
    }

    public function url(): string
    {
        // On a tenant host the central routes are not registered (see
        // RouteServiceProvider::map) — add-on purchases create links there —
        // so build the same /pay/{token} URL on the central app URL.
        if (\Illuminate\Support\Facades\Route::has('central.pay-link.show')) {
            return route('central.pay-link.show', ['token' => $this->token]);
        }

        return \App\Services\EmailNotificationService::centralUrl('pay/' . $this->token);
    }

    /** An offline (bank transfer) proof submitted from this link and awaiting review. */
    public function pendingOfflineAttempt(): ?TenantBillingPayment
    {
        return $this->attempts()
            ->where('gateway', 'offline')
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Settle the link from one of its paid attempts. Idempotent; a cancelled
     * or expired link is still settled — the money has already moved.
     */
    public function markPaidFrom(TenantBillingPayment $payment): void
    {
        if ($this->isPaid()) {
            if ((int) $this->tenant_billing_payment_id !== (int) $payment->id) {
                Log::warning("PaymentLink {$this->id}: second paid attempt {$payment->id} (already settled by {$this->tenant_billing_payment_id}) — review for a refund.");
            }
            return;
        }

        $this->update([
            'status'                    => self::STATUS_PAID,
            'paid_at'                   => $payment->paid_at ?? now(),
            'tenant_billing_payment_id' => $payment->id,
        ]);

        // Other attempts still pending can no longer be needed.
        $this->attempts()
            ->where('id', '!=', $payment->id)
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->where('gateway', '!=', 'offline')
            ->get()
            ->each(fn (TenantBillingPayment $other) => $other->markSuperseded($payment->id));

        Log::info("PaymentLink {$this->id} paid by billing payment {$payment->id} (tenant {$this->tenant_id}).");

        // An add-on purchase / renewal link: switch the add-on on (or extend it).
        if ($this->tenant_addon_id ?? null) {
            try {
                app(\App\Services\Billing\AddonService::class)->onLinkPaid($this, $payment);
            } catch (\Throwable $e) {
                Log::error("PaymentLink {$this->id}: add-on activation failed — {$e->getMessage()}");
            }
        }
    }

    /** The add-on this link buys or renews, when it is an add-on link. */
    public function tenantAddon()
    {
        return $this->belongsTo(TenantAddon::class, 'tenant_addon_id');
    }

    /**
     * The payment that settled the link was refunded. Refunds of any other
     * attempt (e.g. an accidental second payment) leave the link paid.
     */
    public function markRefundedFrom(TenantBillingPayment $payment): void
    {
        if (! $this->isPaid() || (int) $this->tenant_billing_payment_id !== (int) $payment->id) {
            return;
        }

        $this->update(['status' => self::STATUS_REFUNDED]);

        Log::info("PaymentLink {$this->id} refunded (billing payment {$payment->id}).");

        if ($this->tenant_addon_id ?? null) {
            try {
                app(\App\Services\Billing\AddonService::class)->onLinkRefunded($this);
            } catch (\Throwable $e) {
                Log::error("PaymentLink {$this->id}: add-on refund handling failed — {$e->getMessage()}");
            }
        }
    }

    /** Append one entry to the send history (email / WhatsApp). */
    public function logSend(string $channel, string $to, bool $ok, ?int $byUserId = null, ?string $error = null): void
    {
        $log = $this->send_log ?? [];
        $log[] = array_filter([
            'channel' => $channel,
            'to'      => $to,
            'ok'      => $ok,
            'by'      => $byUserId,
            'at'      => now()->toIso8601String(),
            'error'   => $error,
        ], fn ($v) => $v !== null);

        $this->update([
            'send_log'     => array_slice($log, -50),
            'last_sent_at' => $ok ? now() : $this->last_sent_at,
        ]);
    }
}
