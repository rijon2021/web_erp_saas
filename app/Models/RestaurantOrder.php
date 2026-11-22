<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant order: the open check of a seated table (dine-in), or a
 * takeaway / delivery order. Changed only through App\Services\Restaurant\OrderService.
 */
class RestaurantOrder extends Model
{
    use SoftDeletes;

    protected $table = 'rst_orders';

    public const TYPES = ['dine_in', 'takeaway', 'delivery'];

    public const STATUSES = ['open', 'paid', 'cancelled'];

    protected $fillable = [
        'ref', 'type', 'status', 'table_id', 'guests', 'waiter_id', 'warehouse_id', 'client_id', 'customer_name',
        'customer_phone', 'delivery_address', 'note', 'subtotal', 'sale_id', 'opened_at', 'closed_at',
        'cancel_reason', 'cancelled_by', 'source', 'online_order_id',
    ];

    protected $casts = [
        'table_id' => 'integer',
        'guests' => 'integer',
        'waiter_id' => 'integer',
        'warehouse_id' => 'integer',
        'client_id' => 'integer',
        'subtotal' => 'float',
        'sale_id' => 'integer',
        'online_order_id' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** @var array<string, bool> tenant DB name → tables exist */
    private static array $ready = [];

    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('rst_orders') && Schema::hasTable('rst_order_items') && Schema::hasTable('rst_kitchen_tickets');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantOrderItem::class, 'order_id')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(RestaurantKitchenTicket::class, 'order_id')->orderBy('id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id')->withTrashed();
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
