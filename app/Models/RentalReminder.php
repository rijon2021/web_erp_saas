<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One reminder attempt to a rental customer (also the de-duplication record). */
class RentalReminder extends Model
{
    protected $table = 'rental_reminders';

    public const TYPES = ['rent_due', 'rent_overdue', 'contract_expiring', 'return_due'];
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    protected $fillable = [
        'type', 'channel', 'rental_contract_id', 'rental_invoice_id', 'rental_customer_id',
        'recipient', 'status', 'message', 'error', 'manual', 'user_id',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'rental_invoice_id' => 'integer',
        'rental_customer_id' => 'integer',
        'manual' => 'boolean',
        'user_id' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id', 'id');
    }

    public function invoice()
    {
        return $this->belongsTo(RentalInvoice::class, 'rental_invoice_id', 'id');
    }
}
