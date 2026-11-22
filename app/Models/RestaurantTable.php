<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A restaurant table and its live state. Status changes go through
 * App\Services\Restaurant\FloorService, never by writing `status` directly.
 */
class RestaurantTable extends Model
{
    use SoftDeletes;

    protected $table = 'rst_tables';

    public const STATUSES = ['free', 'occupied', 'bill', 'reserved', 'cleaning'];

    public const SHAPES = ['square', 'round', 'long'];

    protected $fillable = [
        'area_id', 'name', 'seats', 'shape', 'sort_order', 'is_active', 'status', 'guests', 'seated_at',
        'waiter_id', 'merged_into_id', 'status_changed_at', 'note',
    ];

    protected $casts = [
        'area_id' => 'integer',
        'seats' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'guests' => 'integer',
        'seated_at' => 'datetime',
        'waiter_id' => 'integer',
        'merged_into_id' => 'integer',
        'status_changed_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(RestaurantArea::class, 'area_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Tables joined to this one (this table is the merge "parent"). */
    public function mergedTables(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** Guests are sitting here (or at the table it is merged into). */
    public function isInUse(): bool
    {
        return in_array($this->status, ['occupied', 'bill'], true);
    }
}
