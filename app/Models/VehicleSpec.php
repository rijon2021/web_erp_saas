<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Car Dealership theme: the vehicle details of one product (make, model,
 * year, mileage …). Price, photos, description and stock stay on the product.
 */
class VehicleSpec extends Model
{
    protected $table = 'vehicle_specs';

    public const CONDITIONS = ['new', 'used', 'certified'];

    public const BODY_TYPES = ['suv', 'sedan', 'hatchback', 'pickup', 'coupe', 'convertible', 'wagon', 'van'];

    public const FUELS = ['petrol', 'diesel', 'hybrid', 'plug_in_hybrid', 'electric', 'lpg'];

    public const TRANSMISSIONS = ['automatic', 'manual', 'cvt'];

    public const DRIVETRAINS = ['fwd', 'rwd', 'awd', '4wd'];

    public const SERVICE_HISTORY = ['full', 'partial', 'none'];

    protected $fillable = [
        'product_id', 'condition', 'make', 'model', 'trim', 'year', 'mileage', 'body_type', 'fuel',
        'transmission', 'drivetrain', 'engine_size', 'power_hp', 'exterior_color', 'interior',
        'doors', 'seats', 'owners', 'fuel_economy', 'service_history', 'vin', 'features',
        'reservable', 'is_sold',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'year' => 'integer',
        'mileage' => 'integer',
        'engine_size' => 'float',
        'power_hp' => 'integer',
        'doors' => 'integer',
        'seats' => 'integer',
        'owners' => 'integer',
        'features' => 'array',
        'reservable' => 'boolean',
        'is_sold' => 'boolean',
    ];

    /** @var array<string, bool> tenant DB name → table exists */
    private static array $ready = [];

    /** Whether the vehicle tables migration has run on the current tenant DB. */
    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('vehicle_specs') && Schema::hasTable('vehicle_leads');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Clean product-form input into fillable attributes. Unknown vocabulary
     * values are dropped (stored as null), numbers are clamped, features are
     * de-duplicated short strings.
     */
    public static function clean(array $in): array
    {
        $str = fn ($k, $max) => ($v = trim((string) (is_scalar($in[$k] ?? null) ? $in[$k] : ''))) === '' ? null : Str::limit($v, $max, '');
        $int = function ($k, $min, $max) use ($in) {
            $v = $in[$k] ?? null;
            if ($v === null || $v === '' || ! is_numeric(str_replace([',', ' '], '', (string) $v))) {
                return null;
            }

            return max($min, min($max, (int) round((float) str_replace([',', ' '], '', (string) $v))));
        };
        $pick = fn ($k, array $allowed) => in_array($v = strtolower(trim((string) ($in[$k] ?? ''))), $allowed, true) ? $v : null;

        $features = $in['features'] ?? [];
        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $features);
        }
        $features = collect(is_array($features) ? $features : [])
            ->filter(fn ($f) => is_scalar($f) && trim((string) $f) !== '')
            ->map(fn ($f) => Str::limit(trim((string) $f), 60, ''))
            ->unique(fn ($f) => mb_strtolower($f))
            ->take(60)
            ->values()
            ->all();

        $engine = $in['engine_size'] ?? null;
        $engine = $engine === null || $engine === '' || ! is_numeric($engine) ? null : max(0, min(99.9, round((float) $engine, 1)));

        return [
            'condition' => $pick('condition', self::CONDITIONS) ?? 'used',
            'make' => $str('make', 80),
            'model' => $str('model', 80),
            'trim' => $str('trim', 120),
            'year' => $int('year', 1900, (int) date('Y') + 2),
            'mileage' => $int('mileage', 0, 9999999),
            'body_type' => $pick('body_type', self::BODY_TYPES),
            'fuel' => $pick('fuel', self::FUELS),
            'transmission' => $pick('transmission', self::TRANSMISSIONS),
            'drivetrain' => $pick('drivetrain', self::DRIVETRAINS),
            'engine_size' => $engine,
            'power_hp' => $int('power_hp', 0, 5000),
            'exterior_color' => $str('exterior_color', 60),
            'interior' => $str('interior', 80),
            'doors' => $int('doors', 0, 9),
            'seats' => $int('seats', 0, 99),
            'owners' => $int('owners', 0, 99),
            'fuel_economy' => $str('fuel_economy', 40),
            'service_history' => $pick('service_history', self::SERVICE_HISTORY),
            'vin' => $str('vin', 40),
            'features' => $features,
            'reservable' => array_key_exists('reservable', $in) ? filter_var($in['reservable'], FILTER_VALIDATE_BOOLEAN) : true,
            'is_sold' => filter_var($in['is_sold'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Product-form payload (every key present, blanks as ''). */
    public function toFormArray(): array
    {
        $out = [];
        foreach ($this->fillable as $k) {
            if ($k === 'product_id') {
                continue;
            }
            $v = $this->{$k};
            $out[$k] = $k === 'features' ? array_values($v ?? []) : ($v ?? (in_array($k, ['reservable', 'is_sold'], true) ? false : ''));
        }

        return $out;
    }

    /** "2020 BMW 3 Series" — the card / page title when the product name is generic. */
    public function headline(): string
    {
        return trim(implode(' ', array_filter([$this->year, $this->make, $this->model])));
    }
}
