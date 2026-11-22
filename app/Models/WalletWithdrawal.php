<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's request to cash out wallet balance (storefront My Wallet).
 * pending -> approved (wallet debited) -> paid (cash left an account);
 * pending|approved -> rejected (an approved one is credited back).
 * All transitions go through CustomerWalletService.
 */
class WalletWithdrawal extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'approved', 'rejected', 'paid'];

    protected $fillable = [
        'wallet_id', 'amount', 'status', 'method', 'destination', 'note', 'wallet_transaction_id',
        'account_id', 'payment_method_id', 'processed_by', 'processed_at', 'paid_at',
    ];

    protected $casts = [
        'wallet_id' => 'integer',
        'amount' => 'double',
        'wallet_transaction_id' => 'integer',
        'account_id' => 'integer',
        'payment_method_id' => 'integer',
        'processed_by' => 'integer',
        'processed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
