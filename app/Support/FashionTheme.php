<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Fashion & Apparel storefront theme — presentation options, stored under
 * store_settings.theme_options['fashion']. Same contract as the other skin
 * themes: defaults() → options() → sanitize(). Also owns the size helpers the
 * fashion product card uses to list sizes and quick-add one.
 */
class FashionTheme
{
    public const KEY = 'fashion';

    public const LISTS = ['slides', 'tiles', 'banners', 'trust'];

    public const SECTIONS = [
        'categories', 'new_arrivals', 'editorial', 'sale', 'popular', 'trust', 'newsletter', 'brands',
    ];

    public const ICONS = ['truck', 'refresh', 'ruler', 'lock', 'shield-check', 'gift', 'star', 'headset', 'package', 'clock', 'check-circle', 'heart', 'phone', 'mail'];

    /** Option names treated as "size" (lower-case substrings). */
    public const SIZE_WORDS = ['size', 'taille', 'talla', 'tamaño', 'tamanho', 'größe', 'groesse', 'maat', 'beden', 'misura', 'rozmiar', 'مقاس', 'المقاس', 'سائز', 'حجم'];

    /** Variant names that read as sizes when a product has no options (legacy flat list). */
    public const SIZE_NAME = '/^(?:XXXS|XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL|One ?size|OS|\d{1,2}(?:[.,]5)?|\d{2}\/\d{2})$/iu';

    public static function defaults(): array
    {
        return [
            'announcement_text' => 'Free shipping over $100 · Free 30-day returns',
            // Hero slides, category tiles and lookbook banners have no stock
            // defaults: each shows only once the owner adds it (Store Settings >
            // Fashion). Category tiles fall back to the store's own categories.
            'slides' => [],
            'tiles' => [],
            'banners' => [],
            'trust' => [
                ['icon' => 'truck', 'title' => 'Free shipping', 'subtitle' => 'On orders over $100'],
                ['icon' => 'refresh', 'title' => 'Free 30-day returns', 'subtitle' => 'Wrong size? Swap it free'],
                ['icon' => 'ruler', 'title' => 'Find your fit', 'subtitle' => 'Sizes listed on every product'],
                ['icon' => 'lock', 'title' => 'Secure payment', 'subtitle' => 'Cards, wallets & cash on delivery'],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => ['new_arrivals' => 8, 'sale' => 4, 'popular' => 4, 'categories' => 4],
            'new_arrivals_title' => 'New arrivals',
            'sale_kicker' => 'End of season',
            'sale_title' => 'The sale edit',
            'popular_title' => 'Best sellers',
            'newsletter_title' => 'Join for early access',
            'newsletter_subtitle' => 'New collections, sale previews and styling notes. No spam.',
            // No tiles added → show the biggest categories (photo from their products).
            'auto_category_tiles' => true,
            'show_card_sizes' => true,
            'quick_add' => true,
            'payment_icons' => true,
            'show_theme_toggle' => false,
        ];
    }

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
        foreach (self::LISTS as $list) {
            if (isset($saved[$list]) && is_array($saved[$list])) {
                $rows = array_values(array_filter($saved[$list], 'is_array'));
                // Only the trust strip keeps defaults; an emptied slides /
                // tiles / banners list really is empty.
                if ($rows !== [] || $list !== 'trust') {
                    $out[$list] = $rows;
                }
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
        foreach (['announcement_text', 'new_arrivals_title', 'sale_kicker', 'sale_title', 'popular_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            if (array_key_exists($k, $saved) && is_string($saved[$k])) {
                if (in_array($k, ['announcement_text', 'sale_kicker'], true) || trim($saved[$k]) !== '') {
                    $out[$k] = trim($saved[$k]);
                }
            }
        }
        foreach (['auto_category_tiles', 'show_card_sizes', 'quick_add', 'payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

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
        $str = fn ($v, $max = 500) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), $max, '');
        $clean = [];
        $fields = [
            'slides' => ['kicker', 'title', 'subtitle', 'image', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'],
            'tiles' => ['title', 'subtitle', 'url', 'image'],
            'banners' => ['kicker', 'title', 'subtitle', 'image', 'button_text', 'url'],
            'trust' => ['icon', 'title', 'subtitle'],
        ];
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
                if ($list === 'trust' && ! in_array($r['icon'], self::ICONS, true)) {
                    $r['icon'] = 'check-circle';
                }
                $probe = $r;
                unset($probe['icon']);
                if (implode('', $probe) === '') {
                    continue;
                }
                $rows[] = $r;
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
        foreach (['new_arrivals', 'sale', 'popular', 'categories'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }
        foreach (['announcement_text', 'new_arrivals_title', 'sale_kicker', 'sale_title', 'popular_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            $clean[$k] = $str($input[$k] ?? '', 255);
        }
        foreach (['auto_category_tiles', 'show_card_sizes', 'quick_add', 'payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $clean;
    }

    /** Same rule as the shop colour filter (StoreFacets::COLOR_NAMES / LIKE %colo%r% / %couleur%). */
    public static function isColorOption(string $name): bool
    {
        $n = mb_strtolower(trim($name));

        return in_array($n, StoreFacets::COLOR_NAMES, true) || (bool) preg_match('/colo.*r|couleur/u', $n);
    }

    public static function isSizeOption(string $name): bool
    {
        $n = Str::lower(trim($name));
        foreach (self::SIZE_WORDS as $w) {
            if (Str::contains($n, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sizes for a product card.
     *
     * Reads the product's Size option (store_variant_options() payload) or,
     * for a legacy flat variant list, variant names that look like sizes.
     * Each size is available when any of its variants can be sold.
     *
     *   [
     *     'sizes'  => [['label' => 'M', 'available' => true, 'variant' => [...]|null], ...],
     *     'quick'  => true when every size is exactly one variant (size-only products),
     *                 so a size button can add that variant without a picker,
     *     'colors' => [['label' => 'Red', 'swatch' => '#c00'], ...] from a Color option,
     *   ]
     *
     * @param  \Illuminate\Support\Collection  $variantPayload  the card's data-variants rows
     */
    public static function cardSizes(?array $variantOptions, $variantPayload, bool $allowOverselling): array
    {
        $out = ['sizes' => [], 'quick' => false, 'colors' => []];
        $variants = collect($variantPayload)->keyBy(fn ($v) => (int) $v['id']);
        if ($variants->isEmpty()) {
            return $out;
        }
        $canSell = fn ($v) => $allowOverselling || ! array_key_exists('stock', $v) || $v['stock'] === null || (int) $v['stock'] > 0;

        if ($variantOptions && ! empty($variantOptions['options'])) {
            $sizeIdx = null;
            foreach ($variantOptions['options'] as $i => $opt) {
                if ($sizeIdx === null && self::isSizeOption((string) ($opt['name'] ?? ''))) {
                    $sizeIdx = $i;
                } elseif (! $out['colors'] && self::isColorOption((string) ($opt['name'] ?? ''))) {
                    $out['colors'] = collect($opt['values'] ?? [])->map(fn ($v) => ['label' => (string) $v['value'], 'swatch' => $v['swatch'] ?? null])->take(6)->values()->all();
                }
            }
            if ($sizeIdx === null) {
                return $out;
            }
            $byValue = [];
            foreach ($variantOptions['map'] ?? [] as $vid => $values) {
                $label = $values[$sizeIdx] ?? null;
                if ($label !== null && $variants->has((int) $vid)) {
                    $byValue[$label][] = $variants[(int) $vid];
                }
            }
            $quick = count($variantOptions['options']) === 1;
            foreach ($variantOptions['options'][$sizeIdx]['values'] ?? [] as $val) {
                $label = (string) $val['value'];
                $rows = $byValue[$label] ?? [];
                if (! $rows) {
                    continue;
                }
                $quick = $quick && count($rows) === 1;
                $out['sizes'][] = [
                    'label' => $label,
                    'available' => collect($rows)->contains($canSell),
                    'variant' => count($rows) === 1 ? $rows[0] : null,
                ];
            }
            $out['quick'] = $quick && $out['sizes'] !== [];

            return $out;
        }

        // Legacy flat list: only when every variant name reads as a size.
        if (! $variants->every(fn ($v) => preg_match(self::SIZE_NAME, trim((string) $v['name'])))) {
            return $out;
        }
        foreach ($variants as $v) {
            $out['sizes'][] = ['label' => trim((string) $v['name']), 'available' => $canSell($v), 'variant' => $v];
        }
        $out['quick'] = true;

        return $out;
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
