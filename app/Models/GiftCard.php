<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A redeemable code the business issues (gift card, voucher, store credit).
 * Redeeming it credits the whole remaining value to a customer's wallet
 * (CustomerWalletService::redeemGiftCard), from the storefront or the admin.
 */
class GiftCard extends Model
{
    use SoftDeletes;

    public const TYPES = ['gift_card', 'voucher', 'store_credit'];

    public const STATUSES = ['active', 'redeemed', 'disabled', 'expired'];

    protected $fillable = [
        'type', 'code', 'name', 'amount', 'balance', 'status', 'expires_at', 'note',
        'created_by', 'redeemed_by', 'redeemed_at', 'wallet_transaction_id',
    ];

    protected $casts = [
        'amount' => 'double',
        'balance' => 'double',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'redeemed_by' => 'integer',
        'created_by' => 'integer',
    ];

    public function redeemer()
    {
        return $this->belongsTo(Client::class, 'redeemed_by');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isRedeemable(): bool
    {
        return $this->status === 'active'
            && ! $this->isExpired()
            && (float) $this->balance > 0;
    }
}
