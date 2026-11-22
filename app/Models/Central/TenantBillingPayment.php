<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;

class TenantBillingPayment extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_billing_payments';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PAID       = 'paid';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_SUPERSEDED,
    ];

    public const GATEWAYS = [
        'stripe'      => 'Stripe',
        'paypal'      => 'PayPal',
        'paystack'    => 'Paystack',
        'flutterwave' => 'Flutterwave',
        'mollie'      => 'Mollie',
        'razorpay'    => 'Razorpay',
        'payfast'     => 'PayFast',
        'ligdicash'   => 'LigdiCash',
        'dodo'        => 'Dodo Payments',
        'waafipay'    => 'WaafiPay',
        'bkash'       => 'bKash',
        'sslcommerz'  => 'SSLCommerz',
        'paymob'      => 'Paymob',
        'myfatoorah'  => 'MyFatoorah',
        'moyasar'     => 'Moyasar',
        'xpay'        => 'XPay',
        'offline'     => 'Bank Transfer',
        'manual'      => 'Manual Payment',
    ];

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'plan_id',
        'payment_link_id',
        'amount',
        'tax',
        'currency',
        'gateway_currency',
        'gateway_amount',
        'exchange_rate',
        'conversion_applied',
        'status',
        'gateway',
        'gateway_payment_id',
        'transaction_id',
        'payment_proof_path',
        'billing_cycle',
        'invoice_number',
        'notes',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'tax'                => 'decimal:2',
        'gateway_amount'     => 'decimal:2',
        'exchange_rate'      => 'decimal:8',
        'conversion_applied' => 'boolean',
        'paid_at'            => 'datetime',
        'metadata'           => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if (empty($payment->invoice_number)) {
                $payment->invoice_number = static::generateInvoiceNumber();
            }
        });

        // Value in the MAIN currency (multi-currency pricing), so revenue
        // totals add up across currencies. Main-currency payments: the amount
        // itself. Others: converted at the rate of the day they are paid.
        static::saving(function (self $payment) {
            if (! static::hasBaseColumns()) {
                return;
            }
            if ($payment->base_amount !== null && ! $payment->isDirty('amount') && ! $payment->isDirty('status')) {
                return;
            }
            $main = strtoupper((string) GeneralSetting::currencyCode());
            $currency = strtoupper((string) ($payment->currency ?: $main));
            if ($currency === $main) {
                $payment->base_amount = $payment->amount;
                $payment->base_currency = $main;
                $payment->base_rate = 1;

                return;
            }
            // Fixed at the moment it is paid; pending ones wait for that.
            if ($payment->status !== self::STATUS_PAID || ($payment->base_amount !== null && ! $payment->isDirty('amount'))) {
                return;
            }
            try {
                $rate = \App\Services\Billing\PlanPricing::rate($currency, $main);
                $payment->base_amount = round((float) $payment->amount * $rate, 2);
                $payment->base_currency = $main;
                $payment->base_rate = $rate;
            } catch (\Throwable $e) {
                // No rate right now: reports fall back to the raw amount;
                // `billing:backfill-base-amounts` fills it in later.
                \Illuminate\Support\Facades\Log::warning("Billing payment {$payment->id}: no {$currency}→{$main} rate for base amount: {$e->getMessage()}");
            }
        });

        // A payment-link attempt settling — by webhook, return leg, stale-payment
        // cron or admin "mark paid" — settles its link. Never breaks the payment path.
        static::updated(function (self $payment) {
            if (! $payment->payment_link_id
                || ! $payment->wasChanged('status')
                || ! in_array($payment->status, [self::STATUS_PAID, self::STATUS_REFUNDED], true)) {
                return;
            }

            try {
                $link = PaymentLink::find($payment->payment_link_id);
                $payment->status === self::STATUS_PAID
                    ? $link?->markPaidFrom($payment)
                    : $link?->markRefundedFrom($payment);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("PaymentLink sync failed for billing payment {$payment->id}: {$e->getMessage()}");
            }
        });

        // A refunded plan payment that also renewed add-ons (metadata.addons,
        // applied by AddonService::applyPlanPayments) takes that time back.
        static::updated(function (self $payment) {
            if (! $payment->wasChanged('status') || $payment->status !== self::STATUS_REFUNDED
                || empty($payment->metadata['addons_applied'])) {
                return;
            }

            try {
                app(\App\Services\Billing\AddonService::class)->onPlanPaymentRefunded($payment);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Add-on refund handling failed for billing payment {$payment->id}: {$e->getMessage()}");
            }
        });
    }

    /** @var bool|null whether the base_* columns exist (multi-currency migration) */
    protected static ?bool $baseColumns = null;

    public static function hasBaseColumns(): bool
    {
        if (static::$baseColumns !== null) {
            return static::$baseColumns;
        }
        try {
            return static::$baseColumns = \Illuminate\Support\Facades\Schema::connection('central')
                ->hasColumn('tenant_billing_payments', 'base_amount');
        } catch (\Throwable) {
            return static::$baseColumns = false;
        }
    }

    public static function forgetBaseColumns(): void
    {
        static::$baseColumns = null;
    }

    /**
     * SQL for a payment's value in the main currency, for revenue sums:
     * base_amount when known, else the raw amount (payments made before
     * multi-currency pricing are all in the main currency).
     */
    public static function revenueSql(string $table = ''): string
    {
        $p = $table !== '' ? $table . '.' : '';

        return static::hasBaseColumns() ? "COALESCE({$p}base_amount, {$p}amount)" : "{$p}amount";
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function subscription()
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function paymentLink()
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    /**
     * Rows a tenant sees in its own billing history: everything except
     * unfinished payment-link attempts (abandoned / cancelled / superseded
     * tries have no plan to "retry"). Keyed on the metadata JSON so it also
     * works before the payment_link_id column is migrated.
     */
    public function scopeVisibleToTenant($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('metadata->payment_link_id')
              ->orWhere('status', self::STATUS_PAID);
        });
    }

    /** Plan payments only — payment-link attempts have no plan to retry. */
    public function scopeWithoutPaymentLinks($query)
    {
        return $query->whereNull('metadata->payment_link_id');
    }

    /**
     * Item name shown on the gateway's hosted checkout. Payment-link attempts
     * carry their own title in metadata; plan payments keep "<Plan> Plan".
     */
    public function checkoutProductName(): string
    {
        return $this->metadata['checkout_name'] ?? (($this->plan->name ?? 'Subscription') . ' Plan');
    }

    public function checkoutDescription(): string
    {
        return $this->metadata['checkout_description'] ?? (ucfirst((string) $this->billing_cycle) . ' subscription');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function markPaid(string $gatewayPaymentId = null, string $transactionId = null): void
    {
        // The money went back: a replayed "paid" webhook or a revisited return
        // page (Stripe sessions stay "paid" after a refund) must not revive it.
        if ($this->isRefunded()) {
            return;
        }

        $data = [
            'status'  => self::STATUS_PAID,
            'paid_at' => now(),
        ];

        if ($gatewayPaymentId) {
            $data['gateway_payment_id'] = $gatewayPaymentId;
        }
        if ($transactionId) {
            $data['transaction_id'] = $transactionId;
        }

        $this->update($data);
    }

    public function markFailed(): void
    {
        // Paid and refunded are final: a late or replayed "failed" notification
        // from a gateway must never undo them.
        if ($this->isPaid() || $this->isRefunded()) {
            return;
        }

        $this->update(['status' => self::STATUS_FAILED]);
    }

    public function markRefunded(string $refundTransactionId = null): void
    {
        $data = ['status' => self::STATUS_REFUNDED];

        if ($refundTransactionId) {
            $data['notes'] = trim(($this->notes ? $this->notes . "\n" : '') . 'Refund ID: ' . $refundTransactionId);
        }

        $this->update($data);
    }

    public function markSuperseded(?int $bySupersedingId = null): void
    {
        $note = $bySupersedingId
            ? 'Superseded by payment #' . $bySupersedingId
            : 'Superseded';

        $this->update([
            'status' => self::STATUS_SUPERSEDED,
            'notes'  => trim(($this->notes ? $this->notes . "\n" : '') . $note),
        ]);
    }

    public function getGatewayLabelAttribute(): string
    {
        return self::GATEWAYS[$this->gateway] ?? ucfirst($this->gateway ?? 'Unknown');
    }

    /**
     * Gateway / transaction references worth showing to a human. Offline
     * payments (bank transfer, cash, manual) store internal placeholders such
     * as "offline_12" or "offline_approved_5" — no gateway ever issued them —
     * so they are hidden; the stored values stay as they are.
     */
    public static function isPlaceholderReference(?string $value): bool
    {
        return $value === null || $value === '' || str_starts_with($value, 'offline_');
    }

    public function getDisplayGatewayPaymentIdAttribute(): ?string
    {
        return self::isPlaceholderReference($this->gateway_payment_id) ? null : $this->gateway_payment_id;
    }

    public function getDisplayTransactionIdAttribute(): ?string
    {
        return self::isPlaceholderReference($this->transaction_id) ? null : $this->transaction_id;
    }

    /**
     * An amount of this payment for display, e.g. "USD $999.99".
     *  - payment in the platform currency: code + platform symbol, but the code
     *    is dropped when the symbol already contains it ("USD$" → "USD$999.99")
     *  - any other currency: "EUR 999.99" (the platform symbol would be wrong)
     */
    public function formatMoney(float|int|string|null $value, bool $withCode = true): string
    {
        $amount       = number_format((float) $value, 2);
        $platformCode = strtoupper(GeneralSetting::currencyCode());
        $code         = strtoupper((string) ($this->currency ?: $platformCode));

        if ($code !== $platformCode) {
            return $code . ' ' . $amount;
        }

        $symbol = trim(GeneralSetting::currencySymbol());
        if ($symbol === '') {
            return $code . ' ' . $amount;
        }
        if (! $withCode || stripos($symbol, $code) !== false) {
            return $symbol . $amount;
        }

        return $code . ' ' . $symbol . $amount;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID       => 'success',
            self::STATUS_PENDING    => 'warning',
            self::STATUS_FAILED     => 'danger',
            self::STATUS_REFUNDED   => 'info',
            self::STATUS_SUPERSEDED => 'secondary',
            default                 => 'secondary',
        };
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->amount + (float) $this->tax;
    }

    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . date('Ym') . '-';
        $last = static::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
