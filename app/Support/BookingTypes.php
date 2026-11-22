<?php

namespace App\Support;

use App\Models\BookingSetting;
use Illuminate\Support\Str;

/**
 * The kinds of booking business a tenant can run, and what each one means for
 * the booking form, the service categories and the website.
 *
 * Each type (salon, clinic, consulting, home_services, auto, classes, events)
 * defines:
 *   fields      the extra fields of the booking form, each with a default
 *               mode (required | optional | off) the owner can change in
 *               Booking Settings (bk_settings.form_fields);
 *   categories  starter service category names;
 *   mode        which booking tab the website opens on: `slot` (pick a free
 *               time) or `request` (send a request with a preferred time);
 *   accent      the website accent colour (white text on it passes 4.5:1);
 *   staff_word  what the website calls a staff member (Stylist, Technician…).
 *
 * COMMON fields apply to every type. Field ids are global: a field shared by
 * two types (first_visit) has one mode. Option values are unique across all
 * fields, so one translation key per value is enough.
 *
 * bk_settings.business_types lists the types this tenant runs; NULL or an
 * empty list means all of them.
 *
 * Labels: every definition carries its English text. The admin app
 * translates with `Bkm_Type_<K>` / `Bkm_Field_<K>` / `Bkm_Opt_<Value>` keys,
 * the storefront (phase 5) with `messages.BkmField<K>` / `messages.BkmOpt<V>`
 * (see label()).
 */
class BookingTypes
{
    public const TYPES = ['salon', 'clinic', 'consulting', 'home_services', 'auto', 'classes', 'events'];

    public const MODES = ['required', 'optional', 'off'];

    /** Fields of the booking form whatever the business type (name, phone and email are always asked). */
    public const COMMON = [
        ['id' => 'contact_method', 'label' => 'How should we reach you?', 'input' => 'choice', 'options' => ['email', 'whatsapp', 'phone_call'], 'default' => 'optional'],
        ['id' => 'notes', 'label' => 'Anything we should know?', 'input' => 'textarea', 'default' => 'optional'],
    ];

    /** @return array<string, array> */
    public static function definitions(): array
    {
        return [
            'salon' => [
                'icon' => 'scissors',
                'label' => 'Salon & spa',
                'hint' => 'Hair, nails, beauty, massage, barbers',
                'accent' => '#B03A64',
                'staff_word' => 'Stylist',
                'mode' => 'slot',
                'fields' => [
                    ['id' => 'first_visit', 'label' => 'This is my first visit', 'input' => 'bool', 'default' => 'optional'],
                    ['id' => 'allergies', 'label' => 'Allergies or sensitivities', 'input' => 'text', 'default' => 'optional'],
                    ['id' => 'hair_length', 'label' => 'Hair length', 'input' => 'choice', 'options' => ['short', 'medium', 'long'], 'default' => 'off'],
                ],
                'categories' => ['Hair', 'Nails', 'Skin & spa', 'Massage', 'Makeup', 'Barber'],
            ],
            'clinic' => [
                'icon' => 'stethoscope',
                'label' => 'Clinic & therapy',
                'hint' => 'Doctors, dentists, physio, therapists',
                'accent' => '#1F5FA6',
                'staff_word' => 'Practitioner',
                'mode' => 'slot',
                'fields' => [
                    ['id' => 'date_of_birth', 'label' => 'Date of birth', 'input' => 'date', 'default' => 'required'],
                    ['id' => 'reason_for_visit', 'label' => 'Reason for visit', 'input' => 'textarea', 'default' => 'required'],
                    ['id' => 'insurance_provider', 'label' => 'Insurance provider', 'input' => 'text', 'default' => 'optional'],
                    ['id' => 'first_visit', 'label' => 'This is my first visit', 'input' => 'bool', 'default' => 'optional'],
                ],
                'categories' => ['Consultations', 'Check-ups', 'Physiotherapy', 'Dental', 'Therapy sessions', 'Tests & screening'],
            ],
            'consulting' => [
                'icon' => 'briefcase',
                'label' => 'Consulting',
                'hint' => 'Advisors, lawyers, accountants, coaches',
                'accent' => '#4338CA',
                'staff_word' => 'Consultant',
                'mode' => 'slot',
                'fields' => [
                    ['id' => 'meeting_type', 'label' => 'Meeting type', 'input' => 'choice', 'options' => ['online', 'in_person'], 'default' => 'required'],
                    ['id' => 'company_name', 'label' => 'Company name', 'input' => 'text', 'default' => 'optional'],
                    ['id' => 'topic', 'label' => 'What would you like to discuss?', 'input' => 'textarea', 'default' => 'optional'],
                    ['id' => 'attachment', 'label' => 'Document to share', 'input' => 'file', 'default' => 'optional'],
                ],
                'categories' => ['Advisory', 'Legal', 'Finance & tax', 'Coaching', 'Workshops'],
            ],
            'home_services' => [
                'icon' => 'house',
                'label' => 'Home services',
                'hint' => 'Cleaning, plumbing, electrical, AC repair',
                'accent' => '#0B6E4F',
                'staff_word' => 'Technician',
                'mode' => 'request',
                'fields' => [
                    ['id' => 'service_address', 'label' => 'Service address', 'input' => 'textarea', 'default' => 'required'],
                    ['id' => 'service_area', 'label' => 'Area', 'input' => 'area', 'default' => 'required'],
                    ['id' => 'property_type', 'label' => 'Property type', 'input' => 'choice', 'options' => ['apartment', 'house', 'office'], 'default' => 'optional'],
                    ['id' => 'problem_photos', 'label' => 'Photo of the issue', 'input' => 'file', 'default' => 'optional'],
                    ['id' => 'access_notes', 'label' => 'Access notes (gate code, parking)', 'input' => 'text', 'default' => 'off'],
                ],
                'categories' => ['Cleaning', 'Plumbing', 'Electrical', 'AC & cooling', 'Painting', 'Pest control'],
            ],
            'auto' => [
                'icon' => 'car',
                'label' => 'Auto service',
                'hint' => 'Car wash, detailing, garage, tyres',
                'accent' => '#B45309',
                'staff_word' => 'Mechanic',
                'mode' => 'slot',
                'fields' => [
                    ['id' => 'vehicle_make', 'label' => 'Vehicle make', 'input' => 'text', 'default' => 'required'],
                    ['id' => 'vehicle_model', 'label' => 'Vehicle model', 'input' => 'text', 'default' => 'required'],
                    ['id' => 'vehicle_year', 'label' => 'Year', 'input' => 'number', 'min' => 1950, 'max' => 2100, 'default' => 'optional'],
                    ['id' => 'plate_number', 'label' => 'Plate number', 'input' => 'text', 'default' => 'optional'],
                    ['id' => 'mileage', 'label' => 'Mileage', 'input' => 'number', 'min' => 0, 'max' => 3000000, 'unit' => 'km', 'default' => 'off'],
                    ['id' => 'pickup_needed', 'label' => 'Please collect the vehicle', 'input' => 'bool', 'default' => 'off'],
                ],
                'categories' => ['Car wash & detailing', 'Oil & service', 'Tyres', 'Repairs', 'Diagnostics'],
            ],
            'classes' => [
                'icon' => 'dumbbell',
                'label' => 'Classes & fitness',
                'hint' => 'Gyms, yoga, dance, tutoring, lessons',
                'accent' => '#0F766E',
                'staff_word' => 'Instructor',
                'mode' => 'slot',
                'fields' => [
                    ['id' => 'participants', 'label' => 'Number of people', 'input' => 'number', 'min' => 1, 'max' => 50, 'default' => 'required', 'seats' => true],
                    ['id' => 'level', 'label' => 'Level', 'input' => 'choice', 'options' => ['beginner', 'intermediate', 'advanced'], 'default' => 'optional'],
                    ['id' => 'health_notes', 'label' => 'Injuries or health notes', 'input' => 'textarea', 'default' => 'optional'],
                ],
                'categories' => ['Fitness', 'Yoga & pilates', 'Dance', 'Tutoring', 'Music lessons', 'Kids classes'],
            ],
            'events' => [
                'icon' => 'camera',
                'label' => 'Photography & events',
                'hint' => 'Photographers, venues, decorators, entertainers',
                'accent' => '#7C3AED',
                'staff_word' => 'Team member',
                'mode' => 'request',
                'fields' => [
                    ['id' => 'event_location', 'label' => 'Event location', 'input' => 'text', 'default' => 'required'],
                    ['id' => 'guests', 'label' => 'Number of guests', 'input' => 'number', 'min' => 1, 'max' => 5000, 'default' => 'optional'],
                    ['id' => 'event_type', 'label' => 'Type of event', 'input' => 'choice', 'options' => ['wedding', 'birthday', 'corporate', 'family_event', 'other_event'], 'default' => 'optional'],
                ],
                'categories' => ['Photography', 'Video', 'Venue hire', 'Decoration', 'Entertainment'],
            ],
        ];
    }

    public static function definition(string $type): ?array
    {
        return self::definitions()[$type] ?? null;
    }

    /** The types this tenant runs, in the fixed order of TYPES (all of them when none are chosen). */
    public static function enabled(?BookingSetting $s = null): array
    {
        $raw = null;
        try {
            if (BookingSetting::tablesReady()) {
                $raw = ($s ?? BookingSetting::current())->business_types;
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
        $raw = array_map('strval', array_filter($raw, 'is_scalar'));

        return array_values(array_filter(self::TYPES, fn ($t) => in_array($t, $raw, true)));
    }

    public static function isEnabled(string $type, ?BookingSetting $s = null): bool
    {
        return in_array($type, self::enabled($s), true);
    }

    /** Every field definition once (common first), keyed by id. */
    public static function allFields(): array
    {
        $out = [];
        foreach (self::COMMON as $f) {
            $out[$f['id']] = $f;
        }
        foreach (self::definitions() as $def) {
            foreach ($def['fields'] as $f) {
                $out[$f['id']] ??= $f;
            }
        }

        return $out;
    }

    /**
     * Booking-form fields for one type (common ones included) with the
     * owner's mode applied; `off` ones left out. `area` fields carry the
     * configured service areas as options (none configured = free text).
     */
    public static function formFields(string $type, ?BookingSetting $s = null): array
    {
        $s = $s ?? BookingSetting::current();
        $modes = self::fieldModes($s);
        $areas = self::serviceAreas($s);
        $out = [];
        foreach (array_merge(self::definition($type)['fields'] ?? [], self::COMMON) as $f) {
            $mode = $modes[$f['id']] ?? $f['default'];
            if ($mode === 'off') {
                continue;
            }
            if ($f['input'] === 'area') {
                $f['options'] = $areas;
            }
            $f['mode'] = $mode;
            $out[] = $f;
        }

        return $out;
    }

    /** field id => mode, merging the owner's choices over each field's default. */
    public static function fieldModes(?BookingSetting $s = null): array
    {
        $saved = [];
        try {
            $saved = (array) (($s ?? BookingSetting::current())->form_fields ?? []);
        } catch (\Throwable $e) {
            $saved = [];
        }
        $out = [];
        foreach (self::allFields() as $id => $f) {
            $mode = $saved[$id] ?? null;
            $out[$id] = in_array($mode, self::MODES, true) ? $mode : $f['default'];
        }

        return $out;
    }

    /** Owner's posted modes cut down to known fields and modes. */
    public static function cleanFieldModes($input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach (array_keys(self::allFields()) as $id) {
            if (isset($input[$id]) && in_array($input[$id], self::MODES, true)) {
                $out[$id] = $input[$id];
            }
        }

        return $out;
    }

    /**
     * One posted value for one field definition, or null when blank /
     * invalid. `file` fields are handled by the upload code (phase 5).
     */
    public static function coerce(array $f, $v)
    {
        switch ($f['input']) {
            case 'choice':
                return is_scalar($v) && in_array((string) $v, $f['options'], true) ? (string) $v : null;
            case 'area':
                $s = trim((string) (is_scalar($v) ? $v : ''));
                if ($s === '') {
                    return null;
                }
                if (! empty($f['options'])) {
                    return in_array($s, $f['options'], true) ? $s : null;
                }

                return Str::limit($s, 120, '');
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
            case 'date':
                $s = is_string($v) ? trim($v) : '';
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                    return null;
                }
                [$y, $m, $d] = array_map('intval', explode('-', $s));

                return checkdate($m, $d, $y) ? $s : null;
            case 'file':
                return null;
            default: // text, textarea
                $s = trim((string) (is_scalar($v) ? $v : ''));

                return $s === '' ? null : Str::limit($s, $f['input'] === 'textarea' ? 2000 : 191, '');
        }
    }

    public static function serviceAreas(?BookingSetting $s = null): array
    {
        try {
            $list = ($s ?? BookingSetting::current())->service_areas ?? [];
        } catch (\Throwable $e) {
            $list = [];
        }

        return self::cleanAreas($list);
    }

    public static function cleanAreas($list): array
    {
        if (is_string($list)) {
            $list = json_decode($list, true) ?: preg_split('/\r\n|\r|\n/', $list);
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            fn ($x) => Str::limit(trim((string) (is_scalar($x) ? $x : '')), 120, ''),
            (array) $list
        ), 'strlen'))), 0, 50);
    }

    public static function suggestedCategories(string $type): array
    {
        return self::definition($type)['categories'] ?? [];
    }

    /**
     * The registry as the admin app needs it: every type (enabled or not)
     * with its fields, the common fields, the enabled list and the modes.
     */
    public static function forAdmin(?BookingSetting $s = null): array
    {
        $ready = BookingSetting::tablesReady();
        $s = $s ?? ($ready ? BookingSetting::current() : new BookingSetting());
        $withKey = fn (array $list) => array_values(array_map(fn ($f) => $f + ['k' => Str::studly($f['id'])], $list));
        $types = [];
        foreach (self::definitions() as $key => $def) {
            $types[$key] = [
                'value' => $key,
                'k' => Str::studly($key),
                'label' => $def['label'],
                'hint' => $def['hint'],
                'icon' => $def['icon'],
                'accent' => $def['accent'],
                'staff_word' => $def['staff_word'],
                'mode' => $def['mode'],
                'fields' => $withKey($def['fields']),
                'categories' => $def['categories'],
            ];
        }

        return [
            'types' => $types,
            'common' => $withKey(self::COMMON),
            'enabled' => self::enabled($ready ? $s : null),
            'chosen' => self::normalize($s->business_types ?? null),
            'field_modes' => self::fieldModes($s),
        ];
    }

    // ------------------------------------------------------------------
    // Storefront labels (phase 5)
    // ------------------------------------------------------------------

    /** messages.<key> when translated, else the English text. */
    public static function label(string $key, string $english): string
    {
        $t = __('messages.'.$key);

        return is_string($t) && $t !== 'messages.'.$key ? $t : $english;
    }

    public static function fieldLabel(array $f): string
    {
        return self::label('BkmField'.Str::studly($f['id']), $f['label']);
    }

    public static function optionLabel(string $value): string
    {
        return self::label('BkmOpt'.Str::studly($value), Str::ucfirst(str_replace('_', ' ', $value)));
    }

    public static function typeLabel(string $type): string
    {
        $def = self::definition($type);

        return self::label('BkmType'.Str::studly($type), $def['label'] ?? Str::ucfirst($type));
    }
}
