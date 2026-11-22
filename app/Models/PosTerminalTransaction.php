<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sale / refund / void sent to a POS device and the device's answer.
 * A sale transaction is "linked" once the POS sale it paid for is saved
 * (sale_id + payment_sale_id set inside the create_pos transaction).
 */
class PosTerminalTransaction extends Model
{
    public const TYPE_SALE = 'sale';
    public const TYPE_REFUND = 'refund';
    public const TYPE_VOID = 'void';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';

    public const FINAL_STATUSES = [
        self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_CANCELLED,
        self::STATUS_FAILED, self::STATUS_TIMEOUT,
    ];

    protected $table = 'pos_terminal_transactions';

    protected $fillable = [
        'pos_terminal_id', 'type', 'status', 'reference', 'amount', 'base_amount',
        'currency', 'installments', 'sale_uuid', 'sale_id', 'payment_sale_id',
        'parent_id', 'provider_reference', 'auth_code', 'card_mask', 'card_brand',
        'response_code', 'message', 'request', 'response', 'user_id', 'warehouse_id',
        'last_polled_at', 'dispatched_at', 'completed_at',
    ];

    protected $casts = [
        'pos_terminal_id' => 'integer',
        'amount' => 'float',
        'base_amount' => 'float',
        'installments' => 'integer',
        'sale_id' => 'integer',
        'payment_sale_id' => 'integer',
        'parent_id' => 'integer',
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'request' => 'array',
        'response' => 'array',
        'last_polled_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id')->withTrashed();
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Approved refunds + voids against this sale transaction. */
    public function reversedAmount(): float
    {
        return (float) $this->children()
            ->where('status', self::STATUS_APPROVED)
            ->sum('amount');
    }

    public function isVoided(): bool
    {
        return $this->children()
            ->where('type', self::TYPE_VOID)
            ->where('status', self::STATUS_APPROVED)
            ->exists();
    }

    public static function newReference(): string
    {
        do {
            $ref = 'PT'.now()->format('ymdHis').str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }
}
