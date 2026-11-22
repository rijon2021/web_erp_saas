<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money received from a rental customer, split across invoices by allocations. */
class RentalPayment extends Model
{
    use SoftDeletes;

    protected $table = 'rental_payments';

    protected $fillable = [
        'payment_number', 'rental_contract_id', 'rental_customer_id', 'client_id', 'amount', 'source',
        'payment_date', 'payment_method_id', 'account_id', 'reference', 'notes', 'user_id',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'rental_customer_id' => 'integer',
        'client_id' => 'integer',
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'payment_method_id' => 'integer',
        'account_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function allocations()
    {
        return $this->hasMany(RentalPaymentAllocation::class, 'rental_payment_id', 'id');
    }

    public function contract()
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }
}
