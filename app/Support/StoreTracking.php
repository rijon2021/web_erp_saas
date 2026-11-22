<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StoreSetting;

/**
 * Storefront Google tag + Meta Pixel (Store Settings → Tracking & Analytics).
 *
 * Tenants store IDs only — never script — and this class validates them and
 * builds the config that store/partials/tracking.blade.php hands to its
 * inline loader. Each field is a comma-separated list of up to MAX_IDS IDs:
 *  - google_tag_id: G-… (GA4), AW-… (Google Ads), GT-… / DC-… (gtag.js),
 *    GTM-… (a Tag Manager container — loaded with its own snippet), or an
 *    Ads conversion "AW-123456789/AbC-label" (loads AW-123456789 and sends
 *    each purchase to that conversion action; the label is case-sensitive).
 *  - meta_pixel_id: numeric Pixel IDs.
 *
 * Consent: when the store's cookie banner is on, Google analytics IDs wait
 * for the "analytics" choice, and Google Ads IDs and the Meta Pixel for the
 * "marketing" choice (cookie-consent partial, `cookie_consent` cookie).
 */
final class StoreTracking
{
    public const MAX_IDS = 5;

    private const GOOGLE_PATTERN = '/^((G|AW|GT|DC|GTM)-[A-Z0-9]{4,20}|AW-\d{4,20}\/[A-Za-z0-9_-]{4,40})$/';
    private const META_PATTERN = '/^\d{8,20}$/';

    /**
     * Split a stored/posted list into trimmed, de-duplicated tokens. $upper
     * upper-cases the ID only: an Ads conversion label after "/" keeps its case.
     */
    public static function tokens($raw, bool $upper = false): array
    {
        if (! is_string($raw)) {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($upper) {
            $parts = array_map(function ($p) {
                $slash = strpos($p, '/');

                return $slash === false ? strtoupper($p) : strtoupper(substr($p, 0, $slash)).substr($p, $slash);
            }, $parts);
        }

        return array_values(array_unique($parts));
    }

    /** Tokens of a Google list that are not valid tag IDs (for validation). */
    public static function invalidGoogle($raw): array
    {
        return array_values(array_filter(
            self::tokens($raw, true),
            fn ($id) => ! preg_match(self::GOOGLE_PATTERN, $id)
        ));
    }

    /** Tokens of a Meta list that are not valid Pixel IDs (for validation). */
    public static function invalidMeta($raw): array
    {
        return array_values(array_filter(
            self::tokens($raw),
            fn ($id) => ! preg_match(self::META_PATTERN, $id)
        ));
    }

    /** Canonical stored form: "G-AAA,AW-BBB", or null when empty. */
    public static function normalize(?string $raw, bool $upper = false): ?string
    {
        $ids = array_slice(self::tokens($raw, $upper), 0, self::MAX_IDS);

        return $ids ? implode(',', $ids) : null;
    }

    /**
     * Config for the storefront loader, or null when nothing is configured.
     *
     * @param  bool  $consentBanner  whether this page can show the cookie
     *                               banner (the Real Estate layout cannot).
     */
    public static function config(?StoreSetting $s, bool $consentBanner = true, $product = null): ?array
    {
        if (! $s) {
            return null;
        }

        $google = array_values(array_filter(
            array_slice(self::tokens($s->google_tag_id ?? null, true), 0, self::MAX_IDS),
            fn ($id) => preg_match(self::GOOGLE_PATTERN, $id)
        ));
        $meta = array_values(array_filter(
            array_slice(self::tokens($s->meta_pixel_id ?? null), 0, self::MAX_IDS),
            fn ($id) => preg_match(self::META_PATTERN, $id)
        ));

        if (! $google && ! $meta) {
            return null;
        }

        $gtm = array_values(array_filter($google, fn ($id) => str_starts_with($id, 'GTM-')));
        // Ads conversions ("AW-1/label") load their AW- tag and are sent on purchase.
        $ads = array_values(array_filter($google, fn ($id) => str_contains($id, '/')));
        $gtag = array_values(array_unique(array_map(
            fn ($id) => explode('/', $id)[0],
            array_diff($google, $gtm)
        )));

        // Cart, order and product prices are all in the BASE currency.
        // store_currency() is memoised per request and is the base one
        // unless the shopper picked another.
        $active = store_currency();
        $currency = ($active['is_base'] ?? true) ? $active['code'] : \App\Services\StoreCurrencyService::base()['code'];

        return [
            'gtag' => $gtag,
            'gtm' => $gtm,
            'ads' => $ads,
            'meta' => $meta,
            'currency' => (string) ($currency ?: 'USD'),
            'consent' => $consentBanner && (bool) ($s->cookie_consent_enabled ?? true),
            'page' => self::page($product),
        ];
    }

    /** The page-level ecommerce event, if this request is one that has one. */
    private static function page($product): ?array
    {
        $request = request();

        if ($request->routeIs('store.product.show') && $product instanceof Product) {
            return [
                'type' => 'view_item',
                'item' => [
                    'id' => (string) $product->id,
                    'name' => (string) $product->name,
                    'price' => round((float) ($product->display_price ?? $product->price ?? 0), 2),
                ],
            ];
        }
        if ($request->routeIs('checkout', 'store.checkout')) {
            return ['type' => 'begin_checkout'];
        }
        if ($request->routeIs('store.thankyou')) {
            return ['type' => 'purchase'];
        }

        return null;
    }
}
