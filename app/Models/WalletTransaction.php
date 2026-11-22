<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    // Sources: what moved the balance. Plain strings so reports can filter on
    // them; labels live in the translations (Wallet_Src_*).
    public const SOURCES = [
        'top_up', 'refund', 'adjustment', 'purchase_payment', 'return_credit',
        'opening_balance_payment', 'reversal',
        // Customer wallet only
        'sale_payment', 'checkout', 'order_refund', 'gift_card', 'withdrawal',
    ];

    /** Sources a customer wallet can carry (filters on the customer pages). */
    public const CLIENT_SOURCES = [
        'top_up', 'refund', 'adjustment', 'sale_payment', 'checkout', 'return_credit',
        'order_refund', 'gift_card', 'withdrawal', 'opening_balance_payment', 'reversal',
    ];

    /** Sources a supplier wallet can carry. */
    public const PROVIDER_SOURCES = [
        'top_up', 'refund', 'adjustment', 'purchase_payment', 'return_credit',
        'opening_balance_payment', 'reversal',
    ];

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_after', 'source', 'reference_type', 'reference_id',
        'account_id', 'payment_method_id', 'date', 'note', 'user_id',
    ];

    protected $casts = [
        'wallet_id' => 'integer',
        'amount' => 'double',
        'balance_after' => 'double',
        'reference_id' => 'integer',
        'account_id' => 'integer',
        'payment_method_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function payment_method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
