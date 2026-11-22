<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Booking storefront theme — presentation options, stored under
 * store_settings.theme_options['booking']. Same contract as the other skin
 * themes: defaults() → options() → sanitize().
 *
 * What the site lists comes from Booking Management: services set up with
 * "Show on website" (bk_services), the bookable team (bk_staff), and the
 * booking rules in Booking Settings. The default copy and accent colour
 * follow the business type (App\Support\BookingTypes), so a salon site and a
 * plumbing site read right before the owner edits anything. No stock photos:
 * the hero image shows only once the owner uploads one.
 */
class BookingTheme
{
    public const KEY = 'booking';

    public const SECTIONS = ['services', 'how', 'team', 'about', 'visit'];

    /** Storefront icon per business type (x-store.icon names). */
    public const TYPE_ICONS = [
        'salon' => 'sparkles', 'clinic' => 'heart', 'consulting' => 'award', 'home_services' => 'home',
        'auto' => 'car', 'classes' => 'users', 'events' => 'camera',
    ];

    /** The business "flavour" the default copy is written for: one type, or 'mixed'. */
    public static function flavour(?array $types = null): string
    {
        $types = $types ?? self::types();

        return count($types) === 1 && isset(self::COPY[$types[0]]) ? $types[0] : 'mixed';
    }

    private static function types(): array
    {
        try {
            return BookingTypes::enabled();
        } catch (\Throwable $e) {
            return BookingTypes::TYPES;
        }
    }

    /** Accent colour of the flavour (white text on it passes 4.5:1). */
    public static function accent(?array $types = null): string
    {
        $flavour = self::flavour($types);

        return $flavour === 'mixed' ? '#0e7c7b' : (BookingTypes::definition($flavour)['accent'] ?? '#0e7c7b');
    }

    public static function defaults(?array $types = null): array
    {
        return self::COPY[self::flavour($types)] + self::baseDefaults();
    }

    /** Default copy per business flavour (see flavour()). */
    private const COPY = [
        'salon' => [
            'hero_title' => 'Look your best. Book in a minute.',
            'hero_subtitle' => 'Choose a service and a stylist, pick a free time, and we confirm your booking.',
        ],
        'clinic' => [
            'hero_title' => 'Book your visit online.',
            'hero_subtitle' => 'Pick a practitioner and a free time. Our front desk confirms your visit.',
        ],
        'consulting' => [
            'hero_title' => 'Book a consultation.',
            'hero_subtitle' => 'Choose a session, meet online or at our office, and get a confirmed time by email.',
        ],
        'home_services' => [
            'hero_title' => 'Tell us what needs fixing.',
            'hero_subtitle' => 'Send a service request with your address and a preferred time. We call back to confirm the visit.',
        ],
        'auto' => [
            'hero_title' => 'Book your car in.',
            'hero_subtitle' => 'Pick a service and a free slot. We confirm and have everything ready when you arrive.',
        ],
        'classes' => [
            'hero_title' => 'Find a class and save your spot.',
            'hero_subtitle' => 'See the free places for each session and book in a minute.',
        ],
        'events' => [
            'hero_title' => 'Tell us about your event.',
            'hero_subtitle' => 'Send the date, place and what you need. We reply with availability and a quote.',
        ],
        'mixed' => [
            'hero_title' => 'Book online in a minute.',
            'hero_subtitle' => 'Choose a service, pick a time that suits you, and we confirm your booking.',
        ],
    ];

    private static function baseDefaults(): array
    {
        return [
            'announcement_text' => '',
            // banners[0] = hero photo, banners[1] = about photo (uploads use the shared banners list).
            'banners' => [['image' => ''], ['image' => '']],
            'hero_image' => '',
            'about_image' => '',
            'hero_kicker' => '',
            'services_title' => 'Services',
            'team_title' => 'Meet the team',
            'about_title' => 'About us',
            'about_text' => '',
            'opening_hours' => '',
            'map_url' => '',
            'whatsapp' => '',
            'sections' => array_fill_keys(self::SECTIONS, true),
            'show_prices' => true,
            'payment_icons' => false,
            'show_theme_toggle' => false,
        ];
    }

    private const TEXTS = [
        'announcement_text', 'hero_kicker', 'hero_title', 'hero_subtitle', 'services_title', 'team_title',
        'about_title', 'about_text', 'opening_hours', 'map_url', 'whatsapp',
    ];

    /** Texts that may be saved empty (the rest fall back to their default). */
    private const BLANKABLE = ['announcement_text', 'hero_kicker', 'about_text', 'opening_hours', 'map_url', 'whatsapp'];

    private const LONG = ['hero_subtitle', 'about_text', 'opening_hours'];

    private const FLAGS = ['show_prices', 'payment_icons', 'show_theme_toggle'];

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
        if (isset($saved['sections']) && is_array($saved['sections'])) {
            foreach (self::SECTIONS as $sec) {
                if (array_key_exists($sec, $saved['sections'])) {
                    $out['sections'][$sec] = filter_var($saved['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        foreach (self::TEXTS as $k) {
            if (array_key_exists($k, $saved) && is_string($saved[$k])) {
                // Saved text equal to some flavour's stock copy was never edited
                // (the settings page shows defaults as values): keep following
                // the current business type instead of freezing it.
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
