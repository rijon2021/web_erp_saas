<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Car Dealership storefront theme — presentation options, stored under
 * store_settings.theme_options['cars']. Same contract as the other skin
 * themes: defaults() → options() → sanitize().
 *
 * Cars are ordinary products; their make / model / year / mileage live in
 * vehicle_specs (App\Models\VehicleSpec). Shoppers send leads (enquiry, test
 * drive, trade-in, finance, reservation) instead of buying online, unless the
 * owner switches online purchase on. A reservation adds the owner's chosen
 * deposit product to the normal cart.
 */
class CarsTheme
{
    public const KEY = 'cars';

    public const LISTS = ['slides', 'trust'];

    public const SECTIONS = ['search', 'body_types', 'featured', 'latest', 'makes', 'finance', 'trade_in', 'trust', 'visit'];

    public const ICONS = ['check-circle', 'shield-check', 'refresh', 'credit-card', 'wallet', 'truck', 'clock', 'star', 'headset', 'award', 'key', 'car', 'gauge', 'phone', 'map-pin', 'lock'];

    public const TERMS = [24, 36, 48, 60, 72, 84];

    public static function defaults(): array
    {
        return [
            'announcement_text' => '',
            // No stock hero: it shows once the owner adds a slide.
            'slides' => [],
            'trust' => [
                ['icon' => 'check-circle', 'title' => 'Inspected & serviced', 'subtitle' => 'Multi-point check before sale'],
                ['icon' => 'shield-check', 'title' => 'Warranty available', 'subtitle' => 'Ask us about cover on any car'],
                ['icon' => 'refresh', 'title' => 'Part-exchange welcome', 'subtitle' => 'Any make, any condition'],
                ['icon' => 'credit-card', 'title' => 'Finance options', 'subtitle' => 'Flexible terms, quick decisions'],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => ['featured' => 8, 'latest' => 8, 'makes' => 12],
            'hero_title' => 'Find the car that fits your life.',
            'hero_subtitle' => 'Every car inspected and priced up front. Book a test drive online and drive away on finance or cash.',
            'featured_title' => 'Featured vehicles',
            'latest_title' => 'Just arrived',
            'finance_title' => 'Drive now, pay monthly.',
            'finance_text' => 'Work out a monthly budget on any car, then send us a finance enquiry. We reply before your test drive.',
            'finance_disclaimer' => 'Representative example only. Finance is subject to status and the lender\'s terms.',
            'trade_title' => 'What\'s your car worth?',
            'trade_text' => 'Tell us about your current car and we\'ll come back with a part-exchange offer.',
            'opening_hours' => '',
            'whatsapp' => '',
            'mileage_unit' => 'km',
            'apr' => 9.9,
            'default_term' => 60,
            'deposit_percent' => 10,
            'show_monthly_on_cards' => true,
            'allow_online_purchase' => false,
            'enable_reserve' => false,
            'reserve_product_id' => null,
            'reserve_days' => 7,
            'notify_owner' => true,
            'crm_capture' => true,
            'payment_icons' => false,
            'show_theme_toggle' => false,
        ];
    }

    private const TEXTS = [
        'announcement_text', 'hero_title', 'hero_subtitle', 'featured_title', 'latest_title', 'finance_title',
        'finance_text', 'finance_disclaimer', 'trade_title', 'trade_text', 'opening_hours', 'whatsapp',
    ];

    /** Texts that may be saved empty (the rest fall back to their default). */
    private const BLANKABLE = ['announcement_text', 'finance_disclaimer', 'opening_hours', 'whatsapp'];

    private const FLAGS = ['show_monthly_on_cards', 'allow_online_purchase', 'enable_reserve', 'notify_owner', 'crm_capture', 'payment_icons', 'show_theme_toggle'];

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
                // Only the trust strip keeps defaults; an emptied slides list really is empty.
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
        if (in_array($saved['mileage_unit'] ?? null, ['km', 'mi'], true)) {
            $out['mileage_unit'] = $saved['mileage_unit'];
        }
        if (isset($saved['apr']) && is_numeric($saved['apr'])) {
            $out['apr'] = max(0, min(99, round((float) $saved['apr'], 2)));
        }
        if (isset($saved['default_term']) && in_array((int) $saved['default_term'], self::TERMS, true)) {
            $out['default_term'] = (int) $saved['default_term'];
        }
        if (isset($saved['deposit_percent']) && is_numeric($saved['deposit_percent'])) {
            $out['deposit_percent'] = max(0, min(90, (int) $saved['deposit_percent']));
        }
        if (isset($saved['reserve_days']) && is_numeric($saved['reserve_days'])) {
            $out['reserve_days'] = max(1, min(60, (int) $saved['reserve_days']));
        }
        if (isset($saved['reserve_product_id']) && is_numeric($saved['reserve_product_id']) && (int) $saved['reserve_product_id'] > 0) {
            $out['reserve_product_id'] = (int) $saved['reserve_product_id'];
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
        foreach (['featured', 'latest', 'makes'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }
        foreach (self::TEXTS as $k) {
            $clean[$k] = $str($input[$k] ?? '', in_array($k, ['finance_text', 'trade_text', 'hero_subtitle', 'finance_disclaimer', 'opening_hours'], true) ? 1000 : 255);
        }
        // WhatsApp: digits only (wa.me format), optional leading +.
        $clean['whatsapp'] = preg_replace('/\D+/', '', $clean['whatsapp']);
        foreach (self::FLAGS as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $clean['mileage_unit'] = in_array($input['mileage_unit'] ?? null, ['km', 'mi'], true) ? $input['mileage_unit'] : 'km';
        if (isset($input['apr']) && is_numeric($input['apr'])) {
            $clean['apr'] = max(0, min(99, round((float) $input['apr'], 2)));
        }
        if (isset($input['default_term']) && in_array((int) $input['default_term'], self::TERMS, true)) {
            $clean['default_term'] = (int) $input['default_term'];
        }
        if (isset($input['deposit_percent']) && is_numeric($input['deposit_percent'])) {
            $clean['deposit_percent'] = max(0, min(90, (int) $input['deposit_percent']));
        }
        if (isset($input['reserve_days']) && is_numeric($input['reserve_days'])) {
            $clean['reserve_days'] = max(1, min(60, (int) $input['reserve_days']));
        }
        $rid = $input['reserve_product_id'] ?? null;
        $clean['reserve_product_id'] = is_numeric($rid) && (int) $rid > 0 ? (int) $rid : null;

        return $clean;
    }

    /** Monthly payment on an amortised loan (0 % APR = straight division). */
    public static function monthly(float $price, array $opts, ?int $term = null, ?float $deposit = null): float
    {
        $term = $term ?: (int) $opts['default_term'];
        $deposit = $deposit ?? $price * ((int) $opts['deposit_percent'] / 100);
        $principal = max(0.0, $price - $deposit);
        if ($principal <= 0 || $term <= 0) {
            return 0.0;
        }
        $r = (float) $opts['apr'] / 100 / 12;

        return $r > 0 ? $principal * $r / (1 - pow(1 + $r, -$term)) : $principal / $term;
    }

    /** "41,200 km" in the store's unit. */
    public static function mileage(?int $value, array $opts): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value).' '.($opts['mileage_unit'] === 'mi' ? __('messages.CarMilesShort') : __('messages.CarKmShort'));
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
