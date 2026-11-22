<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/** Restaurant dining area (Indoor, Terrace, Family hall…). */
class RestaurantArea extends Model
{
    use SoftDeletes;

    protected $table = 'rst_areas';

    protected $fillable = ['name', 'warehouse_id', 'sort_order', 'is_active'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, bool> tenant DB name → tables exist */
    private static array $ready = [];

    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('rst_areas') && Schema::hasTable('rst_tables');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'area_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
