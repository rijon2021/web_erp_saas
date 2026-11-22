<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One "send to kitchen" batch of an order (KOT). */
class RestaurantKitchenTicket extends Model
{
    protected $table = 'rst_kitchen_tickets';

    public const STATUSES = ['pending', 'preparing', 'ready', 'served', 'void'];

    protected $fillable = ['order_id', 'ref', 'course', 'status', 'sent_by', 'sent_at', 'started_at', 'ready_at', 'served_at'];

    protected $casts = [
        'order_id' => 'integer',
        'course' => 'integer',
        'sent_by' => 'integer',
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantOrderItem::class, 'ticket_id')->orderBy('id');
    }
}
