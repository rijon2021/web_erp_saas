<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One dish on a restaurant order, with its priced add-on snapshot and kitchen status. */
class RestaurantOrderItem extends Model
{
    protected $table = 'rst_order_items';

    public const STATUSES = ['new', 'sent', 'preparing', 'ready', 'served', 'void'];

    protected $fillable = [
        'order_id', 'product_id', 'product_variant_id', 'name', 'qty', 'unit_price', 'addons_price', 'line_total',
        'addons', 'addons_label', 'note', 'seat', 'course', 'hold', 'status', 'station_id', 'ticket_id', 'added_by',
        'sent_at', 'voided_at', 'voided_by', 'void_reason', 'sale_id',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'qty' => 'float',
        'unit_price' => 'float',
        'addons_price' => 'float',
        'line_total' => 'float',
        'addons' => 'array',
        'seat' => 'integer',
        'course' => 'integer',
        'hold' => 'boolean',
        'station_id' => 'integer',
        'ticket_id' => 'integer',
        'sale_id' => 'integer',
        'sent_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    public function isActive(): bool
    {
        return $this->status !== 'void';
    }
}
