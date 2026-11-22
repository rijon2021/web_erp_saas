<?php

namespace App\Support;

use App\Models\StoreSetting;

/**
 * Registry of "skin" storefront themes: those that reuse layouts/store.blade.php
 * and only swap the head / header / footer partials plus their own homepage.
 * (Real Estate is a separate layout and is not listed here.)
 */
class StoreThemes
{
    /** Theme keys that have resources/views/store/<key>/partials/{head,header,footer}. */
    public const SKINS = ['electronics', 'toys', 'grocery', 'fashion', 'cars', 'restaurant', 'rental', 'booking', 'electronics_premium'];

    /**
     * Premium themes: only tenants whose plan has the `premium_themes` feature
     * may switch to them. Value = the free theme the storefront falls back to
     * when the plan no longer includes premium themes (the stored choice and
     * its options are kept, so an upgrade brings the premium look back).
     */
    public const PREMIUM = [
        PremiumElectronicsTheme::KEY => ElectronicsTheme::KEY,
    ];

    /** Plan feature key that unlocks every theme in PREMIUM. */
    public const PREMIUM_FEATURE = 'premium_themes';

    /**
     * Themes that always render dark (the shared pages follow via the `dark`
     * class). Empty today: Electronics Premium is light by default with a
     * visitor toggle.
     */
    public const DARK_FIRST = [];

    /** @var array<string, array{allowed: bool, at: int}> Plan check memo, keyed by tenant id (workers serve many tenants). */
    protected static array $premiumAllowed = [];

    /** @return array<string, class-string> */
    public static function classes(): array
    {
        return [
            ElectronicsTheme::KEY => ElectronicsTheme::class,
            ToysTheme::KEY => ToysTheme::class,
            GroceryTheme::KEY => GroceryTheme::class,
            FashionTheme::KEY => FashionTheme::class,
            CarsTheme::KEY => CarsTheme::class,
            RestaurantTheme::KEY => RestaurantTheme::class,
            RentalTheme::KEY => RentalTheme::class,
            BookingTheme::KEY => BookingTheme::class,
            PremiumElectronicsTheme::KEY => PremiumElectronicsTheme::class,
        ];
    }

    public static function options(string $theme, ?StoreSetting $s): array
    {
        $class = self::classes()[$theme] ?? null;

        return $class ? $class::options($s) : [];
    }

    public static function sanitize(string $theme, $input): array
    {
        $class = self::classes()[$theme] ?? null;

        return $class ? $class::sanitize($input) : [];
    }

    public static function isPremium(?string $theme): bool
    {
        return $theme !== null && array_key_exists($theme, self::PREMIUM);
    }

    public static function isDarkFirst(?string $theme): bool
    {
        return $theme !== null && in_array($theme, self::DARK_FIRST, true);
    }

    /** Does the current tenant's plan include premium themes? Memoised per request. */
    public static function premiumAllowed(): bool
    {
        try {
            $tenantKey = (string) (function_exists('tenant') && tenant() ? tenant()->getTenantKey() : '');
        } catch (\Throwable $e) {
            $tenantKey = '';
        }
        // Short-lived: under PHP-FPM this is one request; long-running workers
        // (queues, Octane) re-check after 60 s so a plan change is picked up.
        $hit = self::$premiumAllowed[$tenantKey] ?? null;
        if ($hit !== null && $hit['at'] > time() - 60) {
            return $hit['allowed'];
        }

        try {
            $allowed = (bool) app(\App\Services\TenantLimitsService::class)->hasFeature(self::PREMIUM_FEATURE);
        } catch (\Throwable $e) {
            $allowed = false;
        }
        self::$premiumAllowed[$tenantKey] = ['allowed' => $allowed, 'at' => time()];

        return $allowed;
    }

    /** Clear the memo (tests, or after the plan changed inside one request). */
    public static function forgetPremium(): void
    {
        self::$premiumAllowed = [];
        self::$themeAddon = [];
    }

    /**
     * May the current tenant use this theme? Free themes always; premium ones
     * when the plan has premium_themes, or the theme was bought as an add-on
     * ("theme:<key>", merged into the plan's features).
     */
    public static function allowed(?string $theme): bool
    {
        if (! self::isPremium($theme)) {
            return true;
        }

        return self::premiumAllowed() || self::themeAddonAllowed((string) $theme);
    }

    /** Premium theme keys the current tenant may use. @return string[] */
    public static function allowedPremium(): array
    {
        return array_values(array_filter(array_keys(self::PREMIUM), fn ($k) => self::allowed($k)));
    }

    /** @var array<string, array{allowed: bool, at: int}> per tenant + theme */
    protected static array $themeAddon = [];

    protected static function themeAddonAllowed(string $theme): bool
    {
        try {
            $tenantKey = (string) (function_exists('tenant') && tenant() ? tenant()->getTenantKey() : '');
        } catch (\Throwable $e) {
            $tenantKey = '';
        }
        $memo = $tenantKey . '|' . $theme;
        $hit = self::$themeAddon[$memo] ?? null;
        if ($hit !== null && $hit['at'] > time() - 60) {
            return $hit['allowed'];
        }

        try {
            $allowed = (bool) app(\App\Services\TenantLimitsService::class)
                ->hasFeature(\App\Models\Central\Addon::THEME_PREFIX . $theme);
        } catch (\Throwable $e) {
            $allowed = false;
        }
        self::$themeAddon[$memo] = ['allowed' => $allowed, 'at' => time()];

        return $allowed;
    }

    /**
     * The theme the storefront actually renders for a stored choice: a premium
     * theme the plan no longer covers falls back to its free sibling.
     */
    public static function effective(?string $theme): ?string
    {
        if (self::isPremium($theme) && ! self::allowed($theme)) {
            return self::PREMIUM[$theme];
        }

        return $theme;
    }
}
