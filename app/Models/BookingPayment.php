<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One online deposit attempt for a booking (bk_payments): pending, paid or failed. */
class BookingPayment extends Model
{
    protected $table = 'bk_payments';

    protected $fillable = ['booking_id', 'gateway', 'reference', 'transaction_id', 'amount', 'currency', 'status', 'error', 'paid_at'];

    protected $casts = [
        'booking_id' => 'integer',
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
