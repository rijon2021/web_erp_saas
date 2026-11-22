<?php

namespace App\Support;

/**
 * The currency public pages are showing prices in for THIS request (landing,
 * register, registration checkout). The global view composer hands every view
 * the platform $currencySymbol / $currencyCode; when a page sets a display
 * currency here, the composer hands out that one instead — so every partial
 * of those pages shows the same symbol without each template being changed.
 */
class DisplayCurrency
{
    protected static ?string $code = null;

    public static function set(?string $code): void
    {
        static::$code = $code ? strtoupper($code) : null;
    }

    public static function code(): ?string
    {
        return static::$code;
    }

    public static function clear(): void
    {
        static::$code = null;
    }

    /** @return array{currencySymbol: string, currencyCode: string}|null */
    public static function viewData(): ?array
    {
        if (static::$code === null) {
            return null;
        }

        return [
            'currencySymbol' => SellingCurrencies::symbol(static::$code),
            'currencyCode'   => static::$code,
        ];
    }
}
