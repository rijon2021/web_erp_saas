<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One movement in a contract's security deposit. */
class RentalDepositTransaction extends Model
{
    use SoftDeletes;

    protected $table = 'rental_deposit_transactions';

    public const TYPES = ['collected', 'refunded', 'applied', 'retained'];

    protected $fillable = [
        'rental_contract_id', 'rental_customer_id', 'type', 'amount', 'transaction_date',
        'payment_method_id', 'account_id', 'rental_payment_id', 'rental_handover_id',
        'reference', 'notes', 'user_id',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'rental_customer_id' => 'integer',
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'payment_method_id' => 'integer',
        'account_id' => 'integer',
        'rental_payment_id' => 'integer',
        'rental_handover_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
