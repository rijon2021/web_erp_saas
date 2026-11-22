<?php

namespace App\Support;

/** Data for the public currency switcher (landing pricing, register page). */
class CurrencySwitcher
{
    /**
     * @return array{current: string, options: array<int, array{code: string, label: string}>}|null
     *         null when multi-currency is off or only one currency is offered
     */
    public static function data(string $current): ?array
    {
        $codes = SellingCurrencies::active();
        if (! SellingCurrencies::enabled() || count($codes) < 2) {
            return null;
        }

        return [
            'current' => strtoupper($current),
            'options' => array_map(fn ($c) => [
                'code'  => $c,
                'label' => $c . ' — ' . SellingCurrencies::symbol($c),
            ], $codes),
        ];
    }
}
