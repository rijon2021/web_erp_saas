<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking details of one service product (bk_services). A service product is
 * bookable once it has this row; name, price, tax, category and image stay on
 * the product.
 */
class BookingServiceDetail extends Model
{
    protected $table = 'bk_services';

    public const PRICE_DISPLAYS = ['from', 'fixed', 'hidden'];

    public const BOOKING_MODES = ['both', 'slot', 'request'];

    public const DEFAULT_DURATION = 60;

    protected $fillable = [
        'product_id', 'business_type', 'duration_minutes', 'buffer_before', 'buffer_after', 'seats',
        'price_display', 'booking_modes', 'show_on_website', 'featured', 'sort_order', 'short_description',
        'deposit_type', 'deposit_value',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'duration_minutes' => 'integer',
        'buffer_before' => 'integer',
        'buffer_after' => 'integer',
        'seats' => 'integer',
        'show_on_website' => 'boolean',
        'featured' => 'boolean',
        'sort_order' => 'integer',
        'deposit_value' => 'float',
    ];

    /** @var array<string, bool> tenant DB name → table exists */
    private static array $ready = [];

    /** Whether migration 2026_10_25_000004 has run (and phase 1 before it). */
    public static function tablesReady(): bool
    {
        $key = (string) DB::connection()->getDatabaseName();

        if (! isset(self::$ready[$key])) {
            try {
                self::$ready[$key] = BookingSetting::tablesReady() && Schema::hasTable('bk_services');
            } catch (\Throwable $e) {
                self::$ready[$key] = false;
            }
        }

        return self::$ready[$key];
    }

    public static function forgetReady(): void
    {
        self::$ready = [];
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** The whole time a booking blocks: buffer before + service + buffer after. */
    public function blockedMinutes(): int
    {
        return (int) $this->buffer_before + (int) $this->duration_minutes + (int) $this->buffer_after;
    }

    public function acceptsMode(string $mode): bool
    {
        return $this->booking_modes === 'both' || $this->booking_modes === $mode;
    }

    /** product_id => duration in minutes for the given service products (empty before the migration). */
    public static function durations(array $productIds): array
    {
        if (! $productIds || ! self::tablesReady()) {
            return [];
        }

        return static::whereIn('product_id', $productIds)->pluck('duration_minutes', 'product_id')
            ->map(fn ($m) => (int) $m)->all();
    }
}
