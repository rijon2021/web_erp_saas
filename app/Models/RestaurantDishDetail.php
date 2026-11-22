<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant details of a product sold as a dish: kitchen station, prep time,
 * calories, spice level, dietary tags, meal periods and "sold out today".
 */
class RestaurantDishDetail extends Model
{
    protected $table = 'rst_dish_details';

    public const SPICE_LEVELS = ['mild', 'medium', 'hot'];

    public const DIETARY = ['vegetarian', 'vegan', 'spicy', 'halal', 'gluten_free'];

    public const MEAL_PERIODS = ['breakfast', 'lunch', 'dinner'];

    protected $fillable = ['product_id', 'station_id', 'prep_minutes', 'calories', 'spice_level', 'dietary', 'meal_periods', 'sold_out_on'];

    protected $casts = [
        'product_id' => 'integer',
        'station_id' => 'integer',
        'prep_minutes' => 'integer',
        'calories' => 'integer',
        'dietary' => 'array',
        'meal_periods' => 'array',
        'sold_out_on' => 'date',
    ];

    /** @var array<string, bool> tenant DB name → menu tables exist */
    private static array $ready = [];

    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('rst_dish_details') && Schema::hasTable('rst_addon_groups') && Schema::hasTable('rst_stations');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(RestaurantStation::class, 'station_id');
    }

    /** Sold out for today (the business day in the app timezone). */
    public function isSoldOut(): bool
    {
        return $this->sold_out_on !== null && $this->sold_out_on->isSameDay(now());
    }

    /**
     * Clean product-form / menu-page input into fillable attributes.
     * Unknown tags and periods are dropped; an empty list means "all day".
     */
    public static function clean(array $in): array
    {
        $num = function ($v, int $max) {
            if ($v === null || $v === '' || ! is_numeric($v)) {
                return null;
            }

            return max(0, min($max, (int) round((float) $v)));
        };
        $list = fn ($v, array $allowed) => array_values(array_intersect($allowed, array_map('strval', is_array($v) ? $v : [])));
        $spice = strtolower(trim((string) ($in['spice_level'] ?? '')));

        return [
            'station_id' => isset($in['station_id']) && is_numeric($in['station_id']) && (int) $in['station_id'] > 0 ? (int) $in['station_id'] : null,
            'prep_minutes' => $num($in['prep_minutes'] ?? null, 600),
            'calories' => $num($in['calories'] ?? null, 20000),
            'spice_level' => in_array($spice, self::SPICE_LEVELS, true) ? $spice : null,
            'dietary' => $list($in['dietary'] ?? [], self::DIETARY),
            'meal_periods' => $list($in['meal_periods'] ?? [], self::MEAL_PERIODS),
        ];
    }
}
