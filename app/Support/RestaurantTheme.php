<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Restaurant storefront theme — presentation options, stored under
 * store_settings.theme_options['restaurant']. Same contract as the other skin
 * themes: defaults() → options() → sanitize().
 *
 * The homepage is the menu: categories as sections, dishes with photos,
 * dietary tags and the Restaurant module's add-ons (picked in a dish dialog,
 * priced again at checkout). Online table booking and the opening hours come
 * from Restaurant Settings when the module is on. No stock photos: the hero
 * image shows only once the owner sets one.
 */
class RestaurantTheme
{
    public const KEY = 'restaurant';

    public const SECTIONS = ['hero', 'menu', 'booking', 'about', 'visit'];

    public static function defaults(): array
    {
        return [
            'announcement_text' => '',
            // banners[0] = hero photo, banners[1] = about photo (uploads use the shared banners list).
            'banners' => [['image' => ''], ['image' => '']],
            'hero_image' => '',
            'hero_kicker' => '',
            'hero_title' => 'Fresh food, made to order.',
            'hero_subtitle' => 'Order online for pickup or delivery, or book a table and join us.',
            'menu_title' => 'Our menu',
            'menu_subtitle' => '',
            'booking_title' => 'Book a table',
            'booking_text' => 'Choose a day, a time and how many you are — we\'ll keep a table for you.',
            'about_title' => 'About us',
            'about_text' => '',
            'about_image' => '',
            'opening_hours' => '',
            'map_url' => '',
            'whatsapp' => '',
            'sections' => array_fill_keys(self::SECTIONS, true),
            'show_dietary_filters' => true,
            'show_photos' => true,
            'payment_icons' => false,
            'show_theme_toggle' => false,
        ];
    }

    private const TEXTS = [
        'announcement_text', 'hero_kicker', 'hero_title', 'hero_subtitle', 'menu_title', 'menu_subtitle',
        'booking_title', 'booking_text', 'about_title', 'about_text', 'opening_hours', 'map_url', 'whatsapp',
    ];

    /** Texts that may be saved empty (the rest fall back to their default). */
    private const BLANKABLE = ['announcement_text', 'hero_kicker', 'menu_subtitle', 'about_text', 'opening_hours', 'map_url', 'whatsapp'];

    private const LONG = ['hero_subtitle', 'booking_text', 'about_text', 'opening_hours', 'menu_subtitle'];

    private const FLAGS = ['show_dietary_filters', 'show_photos', 'payment_icons', 'show_theme_toggle'];

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
        // Exactly two photo slots, kept in place (an empty hero keeps the about photo second).
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
