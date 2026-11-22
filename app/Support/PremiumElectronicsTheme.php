<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Electronics Premium storefront theme — presentation options.
 *
 * A premium (plan-gated) skin: see StoreThemes::PREMIUM. Options live under
 * store_settings.theme_options['electronics_premium']. No stock imagery: the
 * hero and side tiles show the owner's own slides/banners, or real products
 * when none are set.
 */
class PremiumElectronicsTheme
{
    public const KEY = 'electronics_premium';

    public const LISTS = ['slides', 'banners', 'trust'];

    public const SECTIONS = ['trust', 'deals', 'categories', 'picks', 'compare', 'brands', 'recent', 'newsletter'];

    public const TRUST_ICONS = ['truck', 'shield-check', 'check-circle', 'clock', 'message', 'gift', 'refresh', 'credit-card', 'star', 'lightning', 'package', 'user', 'phone', 'mail'];

    /** Most spec labels the shop filters / compare table use. */
    public const MAX_FILTER_SPECS = 6;

    /**
     * Hero slider look. full = edge-to-edge image filling the slide with the
     * text on it (the default); split = text beside a framed image (the
     * original bento). Heights / widths apply to the full style.
     */
    public const HERO_STYLES = ['full', 'split'];
    public const HERO_HEIGHTS = ['medium', 'large', 'screen'];
    public const HERO_WIDTHS = ['edge', 'contained'];

    /** Per-slide choices (full style). */
    public const SLIDE_ALIGNS = ['left', 'center', 'right'];
    public const SLIDE_FOCUS = ['center', 'left', 'right', 'top', 'bottom'];
    public const SLIDE_OVERLAYS = ['none', 'light', 'medium', 'strong'];

    public static function defaults(): array
    {
        return [
            'slides' => [],
            'banners' => [],
            'trust' => [
                ['icon' => 'truck', 'title' => __('messages.PxTrustDelivery'), 'subtitle' => __('messages.PxTrustDeliverySub')],
                ['icon' => 'shield-check', 'title' => __('messages.PxTrustWarranty'), 'subtitle' => __('messages.PxTrustWarrantySub')],
                ['icon' => 'refresh', 'title' => __('messages.PxTrustReturns'), 'subtitle' => __('messages.PxTrustReturnsSub')],
                ['icon' => 'credit-card', 'title' => __('messages.PxTrustCheckout'), 'subtitle' => __('messages.PxTrustCheckoutSub')],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => [
                'deals' => 4,
                'picks' => 10,
                'categories' => 8,
            ],
            'newsletter_title' => __('messages.PxNewsletterTitle'),
            'newsletter_subtitle' => __('messages.PxNewsletterSub'),
            // Spec labels offered as shop filters / compare rows. Empty = the
            // most used labels across the catalogue (StoreSpecs::filterLabels).
            'filter_specs' => [],
            'payment_icons' => true,
            // Light by default; the header toggle lets visitors switch to dark.
            'show_theme_toggle' => true,
            // Full-image slider by default (edge to edge, large).
            'hero_style' => 'full',
            'hero_height' => 'large',
            'hero_width' => 'edge',
            // Seconds per slide (autoplay; 0 = off).
            'hero_interval' => 7,
        ];
    }

    /** A slide row with its per-slide choices filled in (align / focus / overlay). */
    public static function slideDefaults(array $row): array
    {
        $row['align'] = in_array($row['align'] ?? '', self::SLIDE_ALIGNS, true) ? $row['align'] : 'left';
        $row['focus'] = in_array($row['focus'] ?? '', self::SLIDE_FOCUS, true) ? $row['focus'] : 'center';
        $row['overlay'] = in_array($row['overlay'] ?? '', self::SLIDE_OVERLAYS, true) ? $row['overlay'] : 'medium';

        return $row;
    }

    /** Effective options: saved values layered over the defaults. */
    public static function options(?StoreSetting $s): array
    {
        $defaults = self::defaults();
        $raw = $s ? ($s->theme_options ?? null) : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $saved = is_array($raw) ? ($raw[self::KEY] ?? []) : [];
        if (! is_array($saved)) {
            $saved = [];
        }

        $out = $defaults;

        // Slides and banners: a saved empty list stays empty (no stock content).
        foreach (['slides', 'banners'] as $list) {
            if (isset($saved[$list]) && is_array($saved[$list])) {
                $out[$list] = array_values(array_filter($saved[$list], 'is_array'));
            }
        }
        // The trust strip falls back to its defaults when emptied.
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

        if (isset($saved['limits']) && is_array($saved['limits'])) {
            foreach ($out['limits'] as $k => $v) {
                if (isset($saved['limits'][$k]) && is_numeric($saved['limits'][$k])) {
                    $out['limits'][$k] = max(1, min(24, (int) $saved['limits'][$k]));
                }
            }
        }

        foreach (['newsletter_title', 'newsletter_subtitle'] as $k) {
            if (isset($saved[$k]) && is_string($saved[$k]) && trim($saved[$k]) !== '') {
                $out[$k] = trim($saved[$k]);
            }
        }
        if (isset($saved['filter_specs']) && is_array($saved['filter_specs'])) {
            $out['filter_specs'] = self::cleanLabels($saved['filter_specs']);
        }

        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (['hero_style' => self::HERO_STYLES, 'hero_height' => self::HERO_HEIGHTS, 'hero_width' => self::HERO_WIDTHS] as $k => $allowed) {
            if (isset($saved[$k]) && in_array($saved[$k], $allowed, true)) {
                $out[$k] = $saved[$k];
            }
        }
        if (isset($saved['hero_interval']) && is_numeric($saved['hero_interval'])) {
            $out['hero_interval'] = max(0, min(20, (int) $saved['hero_interval']));
        }
        $out['slides'] = array_map([self::class, 'slideDefaults'], $out['slides']);

        return $out;
    }

    /**
     * Normalize an admin-submitted options map before saving: keep only known
     * keys, trim strings, coerce booleans. Unknown keys are dropped.
     */
    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }

        $clean = [];
        $str = fn ($v, $max = 500) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), $max, '');

        $fields = [
            'slides' => ['kicker', 'title', 'subtitle', 'image', 'video_url', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'],
            'banners' => ['kicker', 'title', 'subtitle', 'button_text', 'url', 'image', 'badge'],
            'trust' => ['icon', 'title', 'subtitle'],
        ];
        $caps = ['slides' => 6, 'banners' => 2, 'trust' => 6];
        foreach ($fields as $list => $keys) {
            $rows = [];
            foreach ((array) ($input[$list] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $r = [];
                foreach ($keys as $k) {
                    $r[$k] = $str($row[$k] ?? '', $k === 'subtitle' ? 1000 : 500);
                }
                if (isset($r['video_url']) && $r['video_url'] !== '' && ! self::safeUrl($r['video_url'])) {
                    $r['video_url'] = '';
                }
                if ($list === 'trust' && ! in_array($r['icon'], self::TRUST_ICONS, true)) {
                    $r['icon'] = 'check-circle';
                }
                // A row with nothing filled in is an empty editor line.
                if (implode('', $list === 'trust' ? [$r['title'], $r['subtitle']] : $r) === '') {
                    continue;
                }
                // Slide look (full style): enumerated, never free text.
                if ($list === 'slides') {
                    $r = self::slideDefaults(array_merge($r, array_intersect_key($row, array_flip(['align', 'focus', 'overlay']))));
                }
                $rows[] = $r;
                if (count($rows) >= $caps[$list]) {
                    break;
                }
            }
            $clean[$list] = $rows;
        }

        $clean['sections'] = [];
        foreach (self::SECTIONS as $sec) {
            if (isset($input['sections']) && is_array($input['sections']) && array_key_exists($sec, $input['sections'])) {
                $clean['sections'][$sec] = filter_var($input['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $clean['limits'] = [];
        foreach (['deals', 'picks', 'categories'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }

        foreach (['newsletter_title', 'newsletter_subtitle'] as $k) {
            $clean[$k] = $str($input[$k] ?? '', 255);
        }

        $clean['filter_specs'] = self::cleanLabels(is_array($input['filter_specs'] ?? null) ? $input['filter_specs'] : []);

        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (['hero_style' => self::HERO_STYLES, 'hero_height' => self::HERO_HEIGHTS, 'hero_width' => self::HERO_WIDTHS] as $k => $allowed) {
            if (isset($input[$k]) && in_array($input[$k], $allowed, true)) {
                $clean[$k] = $input[$k];
            }
        }
        if (isset($input['hero_interval']) && is_numeric($input['hero_interval'])) {
            $clean['hero_interval'] = max(0, min(20, (int) $input['hero_interval']));
        }

        return $clean;
    }

    /** Distinct, trimmed spec labels (case-insensitive), at most MAX_FILTER_SPECS. */
    public static function cleanLabels(array $labels): array
    {
        $out = [];
        foreach ($labels as $l) {
            $l = Str::limit(trim((string) (is_scalar($l) ? $l : '')), 60, '');
            if ($l === '') {
                continue;
            }
            $out[mb_strtolower($l)] ??= $l; // first spelling wins
            if (count($out) >= self::MAX_FILTER_SPECS) {
                break;
            }
        }

        return array_values($out);
    }

    /** http(s) only — video links are rendered into an iframe/link. */
    public static function safeUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://[^\s"\'<>]+$#i', $url);
    }

    /**
     * Embeddable URL for a YouTube / Vimeo link, or null (the hero then just
     * links out to the video).
     */
    public static function embedUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! self::safeUrl($url)) {
            return null;
        }
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})#', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1].'?autoplay=1&rel=0';
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d{5,12})#', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1].'?autoplay=1';
        }

        return null;
    }

    /** Black or white text for a button filled with $hex (WCAG relative luminance). */
    public static function onColor(string $hex): string
    {
        $rgb = self::rgb($hex);
        if (! $rgb) {
            return '#0A0C10';
        }
        $l = self::luminance($rgb);

        // Contrast vs black = (L+0.05)/0.05, vs white = 1.05/(L+0.05): pick the larger.
        return ($l + 0.05) / 0.05 >= 1.05 / ($l + 0.05) ? '#0A0C10' : '#FFFFFF';
    }

    /** Page background in dark mode (#0A0C10). */
    public const GROUND = [10, 12, 16];

    /** Page background in light mode (#F6F7F9). */
    public const GROUND_LIGHT = [246, 247, 249];

    /**
     * Colours derived from the store's primary colour for a dark page:
     *  - fill: buttons/badges (the brand colour as is)
     *  - on:   text on a fill (black or white, whichever contrasts more)
     *  - text: the colour lightened until it reads at 4.5:1 on the ground
     *
     * @return array{fill: string, on: string, text: string, fill_rgb: string, text_rgb: string}
     */
    public static function palette(?string $primary): array
    {
        $rgb = self::rgb($primary) ?? [91, 140, 255];
        $text = $rgb;
        for ($i = 0; $i < 20 && self::contrast($text, self::GROUND) < 4.5; $i++) {
            $text = array_map(fn ($c) => (int) round($c + (255 - $c) * 0.12), $text);
        }
        // Light mode: darken until it reads at 4.5:1 on the light ground.
        $textLight = $rgb;
        for ($i = 0; $i < 20 && self::contrast($textLight, self::GROUND_LIGHT) < 4.5; $i++) {
            $textLight = array_map(fn ($c) => (int) round($c * 0.88), $textLight);
        }
        $hex = fn ($c) => sprintf('#%02X%02X%02X', $c[0], $c[1], $c[2]);

        return [
            'fill' => $hex($rgb),
            'on' => self::onColor($hex($rgb)),
            'text' => $hex($text),
            'text_light' => $hex($textLight),
            'fill_rgb' => implode(' ', $rgb),
            'text_rgb' => implode(' ', $text),
            'text_light_rgb' => implode(' ', $textLight),
        ];
    }

    /** @return int[]|null */
    protected static function rgb(?string $hex): ?array
    {
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    protected static function luminance(array $rgb): float
    {
        $lin = function ($c) {
            $c = $c / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
    }

    protected static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function imageUrl($value): ?string
    {
        return ElectronicsTheme::imageUrl($value);
    }

    public static function link($value): string
    {
        return ElectronicsTheme::link($value);
    }
}
