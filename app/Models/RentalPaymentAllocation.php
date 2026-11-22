<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalPaymentAllocation extends Model
{
    protected $table = 'rental_payment_allocations';

    protected $fillable = ['rental_payment_id', 'rental_invoice_id', 'amount'];

    protected $casts = [
        'rental_payment_id' => 'integer',
        'rental_invoice_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(RentalPayment::class, 'rental_payment_id', 'id');
    }

    public function invoice()
    {
        return $this->belongsTo(RentalInvoice::class, 'rental_invoice_id', 'id');
    }
}
