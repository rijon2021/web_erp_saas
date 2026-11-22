<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One email / WhatsApp message about a booking (bk_messages): sent, failed or skipped. */
class BookingMessage extends Model
{
    protected $table = 'bk_messages';

    public $timestamps = false;

    protected $fillable = ['booking_id', 'kind', 'channel', 'recipient', 'to', 'status', 'error', 'created_at'];

    protected $casts = [
        'booking_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
