<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'user_id', 'date', 'Ref', 'sale_id', 'delivered_to', 'shipping_address', 'status', 'shipping_details',
        // Courier integration (see the 2026_10_06 courier migration).
        'courier_account_id', 'courier', 'tracking_number', 'courier_reference',
        'courier_status', 'courier_status_raw', 'destination_city', 'weight', 'pieces',
        'cod_amount', 'cod_status', 'cod_remitted_at', 'cod_reference', 'shipping_charges',
        'label_url', 'booked_at', 'delivered_at', 'returned_at', 'last_synced_at', 'courier_meta',
        'return_status', 'return_note', 'return_handled_at', 'return_handled_by', 'sale_return_id',
        'cod_payment_id', 'charges_expense_id', 'sync_error', 'sync_failures',
        'notified_statuses',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'sale_id' => 'integer',
        'courier_account_id' => 'integer',
        'weight' => 'float',
        'pieces' => 'integer',
        'cod_amount' => 'float',
        'shipping_charges' => 'float',
        'cod_remitted_at' => 'datetime',
        'booked_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'courier_meta' => 'array',
        'return_handled_at' => 'datetime',
        'return_handled_by' => 'integer',
        'sale_return_id' => 'integer',
        'cod_payment_id' => 'integer',
        'charges_expense_id' => 'integer',
        'sync_failures' => 'integer',
        'notified_statuses' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function courierAccount()
    {
        return $this->belongsTo(CourierAccount::class)->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('event_at')->orderByDesc('id');
    }

    /** Public tracking page for this shipment, when the account defines one. */
    public function trackingLink(): ?string
    {
        $template = $this->courierAccount?->tracking_url;

        if (! $template || ! $this->tracking_number) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($this->tracking_number), $template);
    }
}
