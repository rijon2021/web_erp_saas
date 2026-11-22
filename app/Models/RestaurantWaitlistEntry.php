<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A walk-in party waiting for a table (App\Services\Restaurant\ReservationService). */
class RestaurantWaitlistEntry extends Model
{
    protected $table = 'rst_waitlist';

    public const STATUSES = ['waiting', 'notified', 'seated', 'left'];

    /** Still in the queue. */
    public const ACTIVE = ['waiting', 'notified'];

    protected $fillable = [
        'customer_name', 'customer_phone', 'party_size', 'quoted_minutes', 'area_id', 'warehouse_id', 'note',
        'status', 'table_id', 'notified_at', 'seated_at', 'left_at', 'added_by',
    ];

    protected $casts = [
        'party_size' => 'integer',
        'quoted_minutes' => 'integer',
        'area_id' => 'integer',
        'warehouse_id' => 'integer',
        'table_id' => 'integer',
        'notified_at' => 'datetime',
        'seated_at' => 'datetime',
        'left_at' => 'datetime',
    ];
}
