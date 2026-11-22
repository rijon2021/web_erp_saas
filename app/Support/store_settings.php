<?php

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;

if (! function_exists('store_settings')) {
    function store_settings(): StoreSetting
    {
        return Cache::remember('store_settings', 600, function () {
            return StoreSetting::query()->first() ?? new StoreSetting;
        });
    }
}

if (! function_exists('store_currency')) {
    /**
     * Multi-Currency: the currency the storefront displays right now
     * (['id','symbol','code','rate','is_base']); base currency, rate 1 when
     * the module is off or nothing is selected.
     */
    function store_currency(): array
    {
        return \App\Services\StoreCurrencyService::active();
    }
}

if (! function_exists('store_with_currency')) {
    /**
     * Join the currency symbol and an already-formatted amount per the
     * System Settings "Currency Position" ('before' "$ 100", 'before_nospace'
     * "$100", 'after' "100 $", 'after_nospace' "100$"). Same rendering as the
     * admin and the PDFs (helpers::with_currency); mirrors
     * window.storeWithCurrency (JS) defined in layouts/store.blade.php.
     */
    function store_with_currency(?string $symbol, $formatted): string
    {
        return \App\utils\helpers::with_currency($symbol, (string) $formatted);
    }
}

if (! function_exists('store_currency_prefix')) {
    /**
     * @deprecated Prefix-only join kept for old cached views; new code must use
     * store_with_currency($symbol, $formatted) so the Currency Position
     * setting is honoured. A symbol ending in a letter ("FCFA") gets a
     * non-breaking space, signs ("$") stay attached.
     */
    function store_currency_prefix(?string $symbol): string
    {
        $symbol = (string) $symbol;

        return preg_match('/\p{L}$/u', $symbol) ? $symbol."\u{00A0}" : $symbol;
    }
}

if (! function_exists('store_money')) {
    /**
     * Format a BASE-currency amount in the active store currency, symbol
     * placed per the Currency Position setting (see store_with_currency).
     * Pass an explicit currency array (e.g.
     * StoreCurrencyService::forDocument($order)) for order pages that must
     * use the order's snapshotted rate.
     */
    function store_money($baseAmount, ?array $currency = null): string
    {
        $c = $currency ?? store_currency();
        $dec = \App\utils\helpers::price_decimals();

        // en-US separators as before; the Hide Zero Decimals setting drops a whole number's ".00".
        return store_with_currency($c['symbol'], \App\utils\helpers::format_price(((float) $baseAmount) * (($c['rate'] ?? 1) ?: 1), $dec, 'comma_dot'));
    }
}
