<?php

namespace App\Support;

use App\Models\RentalSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The kinds of rental business a tenant can run, and what each one means for
 * the item form, the categories and the website.
 *
 * Each type (vehicle, equipment, property, other) defines:
 *   specs       extra properties kept in rental_items.specs (JSON) — what the
 *               item form asks for, what the website shows on cards and in
 *               the item's spec table, and which ones it can filter on;
 *   booking     the extra fields of the website booking form, each with a
 *               default mode (required | optional | off) the owner can change
 *               in Rental Settings (rental_settings.website_fields);
 *   categories  starter category names for the "Add suggested categories"
 *               button.
 *
 * rental_settings.business_types lists the types this tenant runs; NULL or an
 * empty list means all four (how the module behaved before the setting).
 *
 * Labels: every definition carries its English text. The admin app
 * translates with `Rental_Spec_<K>` / `Rental_Opt_<Value>` /
 * `Rental_Field_<Id>` keys, the storefront with `messages.RntSpec<K>` /
 * `messages.RntOpt<Value>` / `messages.RntField<Id>` (see label()).
 */
class RentalTypes
{
    public const TYPES = ['vehicle', 'equipment', 'property', 'other'];

    public const MODES = ['required', 'optional', 'off'];

    /** @return array<string, array> */
    public static function definitions(): array
    {
        return [
            'vehicle' => [
                'icon' => 'car',
                'label' => 'Vehicles',
                'specs' => [
                    ['id' => 'vehicle_class', 'label' => 'Vehicle class', 'input' => 'select', 'filter' => true, 'options' => ['economy', 'compact', 'sedan', 'suv', 'van', 'minibus', 'pickup_truck', 'luxury', 'convertible', 'motorbike', 'truck']],
                    ['id' => 'transmission', 'label' => 'Transmission', 'input' => 'select', 'filter' => true, 'card' => true, 'icon' => 'gearbox', 'options' => ['automatic', 'manual']],
                    ['id' => 'fuel', 'label' => 'Fuel', 'input' => 'select', 'filter' => true, 'card' => true, 'icon' => 'fuel', 'options' => ['petrol', 'diesel', 'hybrid', 'electric', 'lpg']],
                    ['id' => 'seats', 'label' => 'Seats', 'input' => 'number', 'min' => 1, 'max' => 80, 'filter' => 'min', 'card' => true, 'icon' => 'users'],
                    ['id' => 'doors', 'label' => 'Doors', 'input' => 'number', 'min' => 0, 'max' => 8],
                    ['id' => 'bags', 'label' => 'Luggage (bags)', 'input' => 'number', 'min' => 0, 'max' => 30, 'card' => true, 'icon' => 'bag'],
                    ['id' => 'air_conditioning', 'label' => 'Air conditioning', 'input' => 'bool', 'filter' => true],
                    ['id' => 'mileage_limit', 'label' => 'Mileage limit per day', 'input' => 'number', 'min' => 0, 'max' => 100000, 'unit' => 'km', 'hint' => 'Leave empty or 0 for unlimited mileage'],
                    ['id' => 'min_driver_age', 'label' => 'Minimum driver age', 'input' => 'number', 'min' => 16, 'max' => 99],
                    ['id' => 'features', 'label' => 'Features', 'input' => 'multi', 'options' => ['gps', 'bluetooth', 'apple_carplay', 'reverse_camera', 'cruise_control', 'child_seat', 'roof_rack', 'four_wheel_drive', 'snow_chains']],
                ],
                'booking' => [
                    ['id' => 'pickup_location', 'label' => 'Pick-up location', 'input' => 'location', 'default' => 'required'],
                    ['id' => 'return_location', 'label' => 'Return location', 'input' => 'location', 'default' => 'optional'],
                    ['id' => 'driver_age', 'label' => 'Driver age', 'input' => 'number', 'min' => 16, 'max' => 99, 'default' => 'required'],
                    ['id' => 'license_number', 'label' => 'Driving licence number', 'input' => 'text', 'default' => 'required'],
                    ['id' => 'license_expiry', 'label' => 'Driving licence expiry date', 'input' => 'date', 'default' => 'optional'],
                    ['id' => 'license_file', 'label' => 'Photo of the driving licence', 'input' => 'file', 'default' => 'optional'],
                    ['id' => 'flight_number', 'label' => 'Flight number', 'input' => 'text', 'default' => 'off'],
                ],
                'categories' => ['Economy cars', 'SUVs & 4x4', 'Vans & minibuses', 'Luxury cars', 'Motorbikes & scooters', 'Trucks'],
            ],
            'equipment' => [
                'icon' => 'hammer',
                'label' => 'Equipment',
                'specs' => [
                    ['id' => 'power_source', 'label' => 'Power source', 'input' => 'select', 'filter' => true, 'card' => true, 'icon' => 'zap', 'options' => ['electric', 'battery', 'petrol', 'diesel', 'hydraulic', 'pneumatic', 'manual']],
                    ['id' => 'power_rating', 'label' => 'Power / output', 'input' => 'text', 'card' => true, 'icon' => 'gauge', 'placeholder' => 'e.g. 5 kVA, 2000 W, 18 V'],
                    ['id' => 'capacity', 'label' => 'Capacity', 'input' => 'text', 'card' => true, 'icon' => 'boxes', 'placeholder' => 'e.g. 1.5 t, 200 L, 12 m reach'],
                    ['id' => 'weight', 'label' => 'Weight', 'input' => 'number', 'min' => 0, 'max' => 1000000, 'unit' => 'kg', 'card' => true, 'icon' => 'package'],
                    ['id' => 'dimensions', 'label' => 'Dimensions', 'input' => 'text', 'placeholder' => 'e.g. 120 × 60 × 80 cm'],
                    ['id' => 'operator_available', 'label' => 'Operator available', 'input' => 'bool', 'filter' => true],
                    ['id' => 'delivery_available', 'label' => 'Delivery available', 'input' => 'bool', 'filter' => true],
                    ['id' => 'licence_required', 'label' => 'Operator licence required', 'input' => 'bool'],
                    ['id' => 'accessories', 'label' => 'Included accessories', 'input' => 'tags', 'placeholder' => 'Type and press Enter'],
                ],
                'booking' => [
                    ['id' => 'fulfilment', 'label' => 'Pick-up or delivery', 'input' => 'choice', 'options' => ['pickup', 'delivery'], 'default' => 'required'],
                    ['id' => 'delivery_address', 'label' => 'Delivery address', 'input' => 'textarea', 'default' => 'required'],
                    ['id' => 'site_phone', 'label' => 'Contact phone on site', 'input' => 'text', 'default' => 'off'],
                    ['id' => 'operator_needed', 'label' => 'I need an operator', 'input' => 'bool', 'default' => 'optional'],
                    ['id' => 'usage_note', 'label' => 'What will you use it for?', 'input' => 'textarea', 'default' => 'optional'],
                ],
                'categories' => ['Power & generators', 'Construction machinery', 'Tools', 'Lifting & access', 'Cameras & audio', 'Event & party gear'],
            ],
            'property' => [
                'icon' => 'home',
                'label' => 'Properties',
                'specs' => [
                    ['id' => 'max_guests', 'label' => 'Maximum guests', 'input' => 'number', 'min' => 1, 'max' => 500, 'filter' => 'min', 'card' => true, 'icon' => 'users'],
                    ['id' => 'parking', 'label' => 'Parking', 'input' => 'bool', 'filter' => true],
                    ['id' => 'pets_allowed', 'label' => 'Pets allowed', 'input' => 'bool', 'filter' => true],
                    ['id' => 'check_in_time', 'label' => 'Check-in from', 'input' => 'text', 'placeholder' => 'e.g. 14:00'],
                    ['id' => 'check_out_time', 'label' => 'Check-out by', 'input' => 'text', 'placeholder' => 'e.g. 11:00'],
                ],
                'booking' => [
                    ['id' => 'guests', 'label' => 'Number of guests', 'input' => 'number', 'min' => 1, 'max' => 500, 'default' => 'required'],
                    ['id' => 'arrival_time', 'label' => 'Expected arrival time', 'input' => 'text', 'default' => 'optional'],
                    ['id' => 'purpose', 'label' => 'Purpose of the stay', 'input' => 'textarea', 'default' => 'off'],
                ],
                'categories' => ['Apartments', 'Houses & villas', 'Offices', 'Shops', 'Event venues'],
            ],
            'other' => [
                'icon' => 'package',
                'label' => 'Other rentals',
                'specs' => [],
                'booking' => [
                    ['id' => 'usage_note', 'label' => 'What will you use it for?', 'input' => 'textarea', 'default' => 'optional'],
                ],
                'categories' => [],
            ],
        ];
    }

    public static function definition(string $type): ?array
    {
        return self::definitions()[$type] ?? null;
    }

    /** The types this tenant runs, in the fixed order of TYPES (all four when none are chosen). */
    public static function enabled(?RentalSetting $s = null): array
    {
        $raw = null;
        try {
            if (RentalSetting::websiteReady()) {
                $raw = ($s ?? RentalSetting::current())->business_types;
            }
        } catch (\Throwable $e) {
            $raw = null;
        }

        return self::normalize($raw) ?: self::TYPES;
    }

    /** A saved/posted list cut down to known types, in order; [] when nothing valid. */
    public static function normalize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }
        $raw = array_map('strval', $raw);

        return array_values(array_filter(self::TYPES, fn ($t) => in_array($t, $raw, true)));
    }

    public static function isEnabled(string $type, ?RentalSetting $s = null): bool
    {
        return in_array($type, self::enabled($s), true);
    }

    /** @return array<int, array> */
    public static function specFields(string $type): array
    {
        return self::definition($type)['specs'] ?? [];
    }

    /**
     * Keeps only this type's known properties, each coerced to its input
     * kind. Unknown keys, options outside the list and blanks are dropped.
     */
    public static function cleanSpecs(string $type, $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (self::specFields($type) as $f) {
            if (! array_key_exists($f['id'], $input)) {
                continue;
            }
            $v = self::coerce($f, $input[$f['id']]);
            if ($v !== null) {
                $out[$f['id']] = $v;
            }
        }

        return $out;
    }

    /** One value for one field definition, or null when blank / invalid. */
    public static function coerce(array $f, $v)
    {
        switch ($f['input']) {
            case 'select':
            case 'choice':
                return is_scalar($v) && in_array((string) $v, $f['options'], true) ? (string) $v : null;
            case 'number':
                if ($v === null || $v === '' || ! is_numeric($v)) {
                    return null;
                }
                $n = (int) round((float) $v);

                return max($f['min'] ?? 0, min($f['max'] ?? PHP_INT_MAX, $n));
            case 'bool':
                if ($v === null || $v === '') {
                    return null;
                }

                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'multi':
                $list = is_array($v) ? $v : (is_string($v) ? (json_decode($v, true) ?: explode(',', $v)) : []);
                $list = array_values(array_filter($f['options'], fn ($o) => in_array($o, array_map('strval', (array) $list), true)));

                return $list ?: null;
            case 'tags':
                $list = is_array($v) ? $v : (is_string($v) ? (json_decode($v, true) ?: explode(',', $v)) : []);
                $list = array_values(array_unique(array_filter(array_map(
                    fn ($x) => Str::limit(trim((string) (is_scalar($x) ? $x : '')), 60, ''),
                    (array) $list
                ), 'strlen')));

                return $list ? array_slice($list, 0, 20) : null;
            default: // text, textarea
                $s = trim((string) (is_scalar($v) ? $v : ''));

                return $s === '' ? null : Str::limit($s, $f['input'] === 'textarea' ? 1000 : 120, '');
        }
    }

    /** Website booking-form fields of a type with the owner's mode applied; `off` ones left out. */
    public static function bookingFields(string $type, ?RentalSetting $s = null): array
    {
        $s = $s ?? RentalSetting::current();
        $modes = self::fieldModes($s);
        $locations = self::pickupLocations($s);
        $out = [];
        foreach (self::definition($type)['booking'] ?? [] as $f) {
            $mode = $modes[$f['id']] ?? $f['default'];
            if ($mode === 'off') {
                continue;
            }
            // Locations are a pick list: no list configured = collected at the branch.
            if ($f['input'] === 'location') {
                if (! $locations) {
                    continue;
                }
                $f['options'] = $locations;
            }
            $f['mode'] = $mode;
            $out[] = $f;
        }

        return $out;
    }

    /** field id => mode, merging the owner's choices over each field's default. */
    public static function fieldModes(?RentalSetting $s = null): array
    {
        $saved = [];
        try {
            $saved = (array) (($s ?? RentalSetting::current())->website_fields ?? []);
        } catch (\Throwable $e) {
            $saved = [];
        }
        $out = [];
        foreach (self::definitions() as $def) {
            foreach ($def['booking'] as $f) {
                $mode = $saved[$f['id']] ?? null;
                $out[$f['id']] = in_array($mode, self::MODES, true) ? $mode : $f['default'];
            }
        }

        return $out;
    }

    /** Owner's posted modes cut down to known fields and modes. */
    public static function cleanFieldModes($input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach (self::definitions() as $def) {
            foreach ($def['booking'] as $f) {
                if (isset($input[$f['id']]) && in_array($input[$f['id']], self::MODES, true)) {
                    $out[$f['id']] = $input[$f['id']];
                }
            }
        }

        return $out;
    }

    public static function pickupLocations(?RentalSetting $s = null): array
    {
        try {
            $list = ($s ?? RentalSetting::current())->pickup_locations ?? [];
        } catch (\Throwable $e) {
            $list = [];
        }

        return self::cleanLocations($list);
    }

    public static function cleanLocations($list): array
    {
        if (is_string($list)) {
            $list = json_decode($list, true) ?: preg_split('/\r\n|\r|\n/', $list);
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            fn ($x) => Str::limit(trim((string) (is_scalar($x) ? $x : '')), 120, ''),
            (array) $list
        ), 'strlen'))), 0, 30);
    }

    public static function suggestedCategories(string $type): array
    {
        return self::definition($type)['categories'] ?? [];
    }

    /**
     * The registry as the admin app needs it: every type (enabled or not, an
     * existing item may still be of a type the tenant turned off) with its
     * spec and booking fields, plus the enabled list and the field modes.
     */
    public static function forAdmin(?RentalSetting $s = null): array
    {
        $s = $s ?? RentalSetting::current();
        $types = [];
        foreach (self::definitions() as $key => $def) {
            $types[$key] = [
                'value' => $key,
                'label' => $def['label'],
                'specs' => array_values(array_map(fn ($f) => $f + ['k' => Str::studly($f['id'])], $def['specs'])),
                'booking' => array_values(array_map(fn ($f) => $f + ['k' => Str::studly($f['id'])], $def['booking'])),
                'categories' => $def['categories'],
            ];
        }

        return [
            'types' => $types,
            'enabled' => self::enabled($s),
            'chosen' => self::normalize($s->business_types ?? null),
            'field_modes' => self::fieldModes($s),
        ];
    }

    // ------------------------------------------------------------------
    // Storefront labels
    // ------------------------------------------------------------------

    /** messages.<key> when translated, else the English text. */
    public static function label(string $key, string $english): string
    {
        $t = __('messages.'.$key);

        return is_string($t) && $t !== 'messages.'.$key ? $t : $english;
    }

    public static function specLabel(array $f): string
    {
        return self::label('RntSpec'.Str::studly($f['id']), $f['label']);
    }

    public static function fieldLabel(array $f): string
    {
        return self::label('RntField'.Str::studly($f['id']), $f['label']);
    }

    public static function optionLabel(string $value): string
    {
        return self::label('RntOpt'.Str::studly($value), Str::ucfirst(str_replace('_', ' ', $value)));
    }

    public static function typeLabel(string $type): string
    {
        $def = self::definition($type);

        return self::label('RntType'.Str::studly($type), $def['label'] ?? Str::ucfirst($type));
    }

    /** A stored spec value as display text ("Automatic", "5 seats", "Yes"), or null. */
    public static function display(array $f, $v): ?string
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        switch ($f['input']) {
            case 'select':
            case 'choice':
                return self::optionLabel((string) $v);
            case 'bool':
                return $v ? __('messages.RntYes') : __('messages.RntNo');
            case 'multi':
                return implode(', ', array_map(fn ($o) => self::optionLabel((string) $o), (array) $v));
            case 'tags':
                return implode(', ', (array) $v);
            case 'number':
                if (($f['id'] ?? '') === 'mileage_limit' && (int) $v === 0) {
                    return self::label('RntUnlimited', 'Unlimited');
                }

                return number_format((float) $v, 0).(! empty($f['unit']) ? ' '.$f['unit'].(($f['id'] ?? '') === 'mileage_limit' ? '/'.self::label('RntDayShort', 'day') : '') : '');
            default:
                return (string) $v;
        }
    }
}
