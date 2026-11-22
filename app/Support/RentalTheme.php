<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Rental storefront theme — presentation options, stored under
 * store_settings.theme_options['rental']. Same contract as the other skin
 * themes: defaults() → options() → sanitize().
 *
 * The listings are the Rental module's items (not products): only the
 * business types the tenant runs (Rental Settings › Business type) are shown,
 * each with its own properties, filters and booking-form fields
 * (App\Support\RentalTypes). Visitors send booking requests that land in
 * Rental › Reservations. The default texts follow the business type, so a car
 * hire site and a tool hire site read right before the owner edits anything.
 * No stock photos: the hero image shows only once the owner uploads one.
 */
class RentalTheme
{
    public const KEY = 'rental';

    public const SECTIONS = ['categories', 'featured', 'how', 'trust', 'about', 'visit'];

    public const ICONS = ['check-circle', 'shield-check', 'clock', 'wallet', 'truck', 'key', 'star', 'headset', 'calendar', 'map-pin', 'zap', 'award'];

    /** The business "flavour" the default copy is written for. */
    public static function flavour(?array $types = null): string
    {
        $types = $types ?? self::types();
        if (count($types) === 1 && in_array($types[0], ['vehicle', 'equipment', 'property'], true)) {
            return $types[0];
        }

        return 'mixed';
    }

    private static function types(): array
    {
        try {
            return RentalTypes::enabled();
        } catch (\Throwable $e) {
            return RentalTypes::TYPES;
        }
    }

    public static function defaults(?array $types = null): array
    {
        return self::COPY[self::flavour($types)] + self::baseDefaults();
    }

    /** Default copy per business flavour (see flavour()). */
    private const COPY = [
            'vehicle' => [
                'hero_title' => 'Rent the right car for every trip.',
                'hero_subtitle' => 'Clear daily prices, well-kept vehicles and a booking request that takes a minute. We confirm it quickly.',
                'featured_title' => 'Popular vehicles',
            ],
            'equipment' => [
                'hero_title' => 'Professional equipment, ready when you are.',
                'hero_subtitle' => 'Machines, tools and gear for the job — by the hour, day or week. Pick it up or have it delivered.',
                'featured_title' => 'Popular equipment',
            ],
            'property' => [
                'hero_title' => 'Find your next place to stay.',
                'hero_subtitle' => 'Check the dates that suit you and send a booking request — we reply quickly.',
                'featured_title' => 'Featured places',
            ],
            'mixed' => [
                'hero_title' => 'Rent what you need, when you need it.',
                'hero_subtitle' => 'Choose your dates, see the price straight away and send a booking request in a minute.',
                'featured_title' => 'Popular rentals',
            ],
        ];

    /** Defaults that don't depend on the business type. */
    private static function baseDefaults(): array
    {
        return [
            'announcement_text' => '',
            // banners[0] = hero photo, banners[1] = about photo (uploads use the shared banners list).
            'banners' => [['image' => ''], ['image' => '']],
            'hero_image' => '',
            'about_image' => '',
            'hero_kicker' => '',
            'about_title' => 'About us',
            'about_text' => '',
            'opening_hours' => '',
            'map_url' => '',
            'whatsapp' => '',
            'trust' => [
                ['icon' => 'wallet', 'title' => 'Clear prices', 'subtitle' => 'See the full rental price before you book'],
                ['icon' => 'calendar', 'title' => 'Live availability', 'subtitle' => 'Only dates that are really free'],
                ['icon' => 'shield-check', 'title' => 'Checked before every rental', 'subtitle' => 'Cleaned, inspected and ready to go'],
                ['icon' => 'headset', 'title' => 'Real people', 'subtitle' => 'Call or message us any time'],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => ['featured' => 8],
            'show_deposit' => true,
            'payment_icons' => false,
            'show_theme_toggle' => false,
        ];
    }

    private const TEXTS = [
        'announcement_text', 'hero_kicker', 'hero_title', 'hero_subtitle', 'featured_title',
        'about_title', 'about_text', 'opening_hours', 'map_url', 'whatsapp',
    ];

    /** Texts that may be saved empty (the rest fall back to their default). */
    private const BLANKABLE = ['announcement_text', 'hero_kicker', 'about_text', 'opening_hours', 'map_url', 'whatsapp'];

    private const LONG = ['hero_subtitle', 'about_text', 'opening_hours'];

    private const FLAGS = ['show_deposit', 'payment_icons', 'show_theme_toggle'];

    public static function options(?StoreSetting $s): array
    {
        $out = self::defaults();
        $raw = $s ? ($s->theme_options ?? null) : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $saved = is_array($raw) ? ($raw[self::KEY] ?? []) : [];
        if (! is_array($saved)) {
            return $out;
        }
        if (isset($saved['trust']) && is_array($saved['trust'])) {
            $rows = array_values(array_filter($saved['trust'], 'is_array'));
            if ($rows !== []) {
                $out['trust'] = $rows;
            }
        }
        if (isset($saved['sections']) && is_array($saved['sections'])) {
            foreach (self::SECTIONS as $sec) {
                if (array_key_exists($sec, $saved['sections'])) {
                    $out['sections'][$sec] = filter_var($saved['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        if (isset($saved['limits']['featured']) && is_numeric($saved['limits']['featured'])) {
            $out['limits']['featured'] = max(1, min(24, (int) $saved['limits']['featured']));
        }
        foreach (self::TEXTS as $k) {
            if (array_key_exists($k, $saved) && is_string($saved[$k])) {
                // A saved text equal to some business type's stock copy was never
                // edited (the settings page shows the defaults as values): keep
                // following the current business type instead of freezing it.
                if (self::isStockCopy($k, trim($saved[$k]))) {
                    continue;
                }
                if (in_array($k, self::BLANKABLE, true) || trim($saved[$k]) !== '') {
                    $out[$k] = trim($saved[$k]);
                }
            }
        }
        foreach (self::FLAGS as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach ([0, 1] as $i) {
            $img = $saved['banners'][$i]['image'] ?? '';
            $out['banners'][$i]['image'] = is_string($img) ? trim($img) : '';
        }
        $out['hero_image'] = $out['banners'][0]['image'];
        $out['about_image'] = $out['banners'][1]['image'];

        return $out;
    }

    private static function isStockCopy(string $key, string $value): bool
    {
        foreach (self::COPY as $copy) {
            if (isset($copy[$key]) && $copy[$key] === $value) {
                return true;
            }
        }

        return false;
    }

    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }
        $str = fn ($v, $max) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), $max, '');
        $clean = ['sections' => []];
        foreach (self::SECTIONS as $sec) {
            if (isset($input['sections']) && is_array($input['sections']) && array_key_exists($sec, $input['sections'])) {
                $clean['sections'][$sec] = filter_var($input['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (self::TEXTS as $k) {
            $clean[$k] = $str($input[$k] ?? '', in_array($k, self::LONG, true) ? 2000 : 500);
        }
        $clean['whatsapp'] = preg_replace('/\D+/', '', $clean['whatsapp']);
        // Only web links for the map (no javascript: URLs).
        if ($clean['map_url'] !== '' && ! preg_match('#^https?://#i', $clean['map_url'])) {
            $clean['map_url'] = '';
        }
        foreach (self::FLAGS as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $clean['limits'] = [];
        if (isset($input['limits']['featured']) && is_numeric($input['limits']['featured'])) {
            $clean['limits']['featured'] = max(1, min(24, (int) $input['limits']['featured']));
        }
        $trust = [];
        foreach ((array) ($input['trust'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $r = ['icon' => in_array($row['icon'] ?? '', self::ICONS, true) ? $row['icon'] : 'check-circle', 'title' => $str($row['title'] ?? '', 120), 'subtitle' => $str($row['subtitle'] ?? '', 255)];
            if ($r['title'] !== '' || $r['subtitle'] !== '') {
                $trust[] = $r;
            }
        }
        $clean['trust'] = array_slice($trust, 0, 8);
        // Exactly two photo slots, kept in place.
        $clean['banners'] = [];
        foreach ([0, 1] as $i) {
            $img = $input['banners'][$i]['image'] ?? '';
            $clean['banners'][$i] = ['image' => $str($img, 500)];
        }

        return $clean;
    }

    public static function imageUrl($value): ?string
    {
        return ElectronicsTheme::imageUrl($value);
    }
}
