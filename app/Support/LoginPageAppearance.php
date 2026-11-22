<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Look of the tenant login page (Settings > Login Page).
 *
 * Options are stored as JSON in settings.login_page_options; the hero colour
 * (login_bg_color) and hero image (login_bg_image) have their own columns.
 * Every value is whitelisted here, because colours and the image URL are
 * rendered into an inline style. The view gets CSS custom properties plus
 * body data-attributes; css/auth.css holds the defaults, so an empty
 * options set renders the stock page.
 *
 * public/assets_super/js/auth-login-preview.js mirrors cssVars() for the
 * live preview in settings — keep the two in step.
 */
class LoginPageAppearance
{
    public const DEFAULTS = [
        'layout' => 'split',
        'hero_align' => 'left',
        'panel_align' => 'left',
        'hero_bg_type' => 'gradient',
        'hero_color_2' => null,
        'hero_gradient_angle' => 140,
        'hero_overlay' => 45,
        'hero_text_color' => null,
        'page_bg_color' => null,
        'card_bg_color' => null,
        'text_color' => null,
        'primary_color' => null,
        'button_text_color' => null,
        'card_radius' => 24,
        'card_shadow' => true,
        'input_style' => 'pill',
        'show_logo' => true,
        'show_forgot_password' => true,
        'show_password_toggle' => true,
        'show_remember_me' => false,
    ];

    private const ENUMS = [
        'layout' => ['split', 'split_reverse', 'centered'],
        'hero_align' => ['left', 'center'],
        'panel_align' => ['left', 'center'],
        'hero_bg_type' => ['gradient', 'solid', 'image'],
        'input_style' => ['pill', 'rounded', 'square'],
    ];

    private const COLORS = [
        'hero_color_2', 'hero_text_color', 'page_bg_color', 'card_bg_color',
        'text_color', 'primary_color', 'button_text_color',
    ];

    private const RANGES = [
        'hero_gradient_angle' => [0, 360],
        'hero_overlay' => [0, 90],
        'card_radius' => [0, 40],
    ];

    private const FLAGS = [
        'card_shadow', 'show_logo', 'show_forgot_password', 'show_password_toggle', 'show_remember_me',
    ];

    private const INPUT_RADIUS = ['pill' => '999px', 'rounded' => '12px', 'square' => '4px'];

    /** Defaults overlaid with the valid entries of $raw (array or JSON). */
    public static function sanitize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $out = self::DEFAULTS;

        foreach (self::ENUMS as $key => $allowed) {
            if (isset($raw[$key]) && in_array($raw[$key], $allowed, true)) {
                $out[$key] = $raw[$key];
            }
        }
        foreach (self::COLORS as $key) {
            $out[$key] = self::color($raw[$key] ?? null);
        }
        foreach (self::RANGES as $key => [$min, $max]) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                $out[$key] = max($min, min($max, (int) $raw[$key]));
            }
        }
        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    public static function color($value): ?string
    {
        return is_string($value) && preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) ? $value : null;
    }

    /** Saved options for a settings row. */
    public static function options(?Setting $setting): array
    {
        $stored = $setting->login_page_options ?? null;
        $options = self::sanitize($stored);

        // A hero colour saved before these options existed was a flat fill.
        if (blank($stored) && self::color($setting->login_bg_color ?? null)) {
            $options['hero_bg_type'] = 'solid';
        }

        return $options;
    }

    public static function imageUrl(?Setting $setting): ?string
    {
        return filled($setting->login_bg_image ?? null)
            ? global_asset(upload_path('settings').'/'.$setting->login_bg_image)
            : null;
    }

    /**
     * What auth/login.blade.php renders: the options, the inline style for
     * <body> and its data-attributes.
     */
    public static function forView(?Setting $setting): array
    {
        $options = self::options($setting);
        $vars = self::cssVars($options, $setting->login_bg_color ?? null, self::imageUrl($setting));

        return [
            'options' => $options,
            'style' => collect($vars)->map(fn ($value, $name) => "$name: $value")->implode('; '),
            'attributes' => [
                'data-layout' => $options['layout'],
                'data-hero-align' => $options['hero_align'],
                'data-panel-align' => $options['panel_align'],
                'data-card-shadow' => $options['card_shadow'] ? 'on' : 'off',
            ],
        ];
    }

    /** CSS custom properties that differ from the auth.css defaults. */
    public static function cssVars(array $options, ?string $heroColor, ?string $imageUrl): array
    {
        $color = self::color($heroColor);

        $vars = [
            '--login-hero-bg' => self::heroBackground($options, $color, $imageUrl),
            '--login-hero-text' => $options['hero_text_color'],
            '--login-page-bg' => $options['page_bg_color'],
            '--login-card-bg' => $options['card_bg_color'],
            '--login-text' => $options['text_color'],
            '--login-primary' => $options['primary_color'],
            '--login-btn-text' => $options['button_text_color'],
            '--login-card-radius' => $options['card_radius'].'px',
            '--login-input-radius' => self::INPUT_RADIUS[$options['input_style']],
        ];

        return array_filter($vars, fn ($value) => $value !== null);
    }

    private static function heroBackground(array $options, ?string $color, ?string $imageUrl): ?string
    {
        $type = $options['hero_bg_type'];

        if ($type === 'image' && $imageUrl) {
            $shade = round($options['hero_overlay'] / 100, 2);
            $url = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $imageUrl);

            return "linear-gradient(rgba(0, 0, 0, $shade), rgba(0, 0, 0, $shade)), "
                ."url(\"$url\") center / cover no-repeat ".($color ?? '#6237ff');
        }

        if ($type === 'solid') {
            return $color ?? '#6237ff';
        }

        // Untouched gradient: keep the stock three-stop one from auth.css.
        if (! $color && ! $options['hero_color_2'] && $options['hero_gradient_angle'] === 140) {
            return null;
        }

        return sprintf(
            'linear-gradient(%ddeg, %s, %s)',
            $options['hero_gradient_angle'],
            $color ?? '#7a4dff',
            $options['hero_color_2'] ?? '#4f2bf8'
        );
    }
}
