<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One courier API call, webhook or error (see App\Services\Couriers\CourierLogger). */
class CourierLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['test', 'book', 'rates', 'cities', 'label', 'cancel', 'track', 'webhook', 'other'];

    protected $table = 'courier_logs';

    protected $fillable = [
        'courier_account_id', 'shipment_id', 'courier', 'action', 'direction', 'level',
        'method', 'url', 'http_status', 'duration_ms', 'message', 'request', 'response', 'created_at',
    ];

    protected $casts = [
        'courier_account_id' => 'integer',
        'shipment_id' => 'integer',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id')->withTrashed();
    }
}
