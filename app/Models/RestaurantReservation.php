<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A table booking. Times are wall-clock in the tenant's timezone. Changed
 * only through App\Services\Restaurant\ReservationService.
 */
class RestaurantReservation extends Model
{
    protected $table = 'rst_reservations';

    public const STATUSES = ['booked', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'];

    /** Bookings that still hold their table. */
    public const ACTIVE = ['booked', 'confirmed', 'seated'];

    /** Not arrived yet. */
    public const UPCOMING = ['booked', 'confirmed'];

    public const SOURCES = ['phone', 'walk_in', 'online', 'other'];

    protected $fillable = [
        'customer_name', 'customer_phone', 'customer_email', 'client_id', 'party_size', 'starts_at', 'ends_at',
        'table_id', 'area_id', 'warehouse_id', 'status', 'source', 'note', 'order_id', 'seated_at', 'completed_at',
        'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'party_size' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'table_id' => 'integer',
        'area_id' => 'integer',
        'warehouse_id' => 'integer',
        'order_id' => 'integer',
        'seated_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @var array<string, bool> tenant DB name → tables exist */
    private static array $ready = [];

    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('rst_reservations') && Schema::hasTable('rst_waitlist');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id')->withTrashed();
    }

    public function isUpcoming(): bool
    {
        return in_array($this->status, self::UPCOMING, true);
    }
}
