<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintfulOrder extends Model
{
    /** Waiting for the queue to send it. */
    public const STATUS_QUEUED = 'queued';

    /** Printful refused it (or it could not be reached); retry from the Orders tab. */
    public const STATUS_ERROR = 'error';

    /** Printful statuses after which nothing more happens to the order. */
    public const FINAL_STATUSES = ['canceled', 'fulfilled', 'archived'];

    protected $table = 'printful_orders';

    protected $fillable = [
        'online_order_id', 'printful_order_id', 'external_id', 'status', 'items', 'costs',
        'total_cost', 'currency', 'shipments', 'carrier', 'tracking_number', 'tracking_url',
        'shipped_at', 'error', 'attempts', 'retry_at', 'submitted_at',
    ];

    protected $casts = [
        'online_order_id' => 'integer',
        'printful_order_id' => 'integer',
        'items' => 'array',
        'costs' => 'array',
        'total_cost' => 'float',
        'shipments' => 'array',
        'shipped_at' => 'datetime',
        'attempts' => 'integer',
        'retry_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function onlineOrder(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }

    /** Created in Printful and still a draft the store has to confirm. */
    public function isDraft(): bool
    {
        return $this->printful_order_id && $this->status === 'draft';
    }

    /** Printful cancels only drafts and pending orders (not once in review / production). */
    public function isCancellable(): bool
    {
        return $this->printful_order_id && in_array($this->status, ['draft', 'pending'], true);
    }
}
