<?php

namespace App\Support;

/**
 * ISO 4217 currency code registry.
 *
 * Every payment gateway rejects a session opened with an unknown currency
 * code, and no exchange-rate provider can convert from one, so a misspelled
 * platform currency (e.g. "DBT" instead of "BDT") silently breaks checkout.
 * Settings forms validate against this list, and checkout refuses to start
 * with a code that isn't in it.
 */
final class IsoCurrencies
{
    /** @var string[] Active ISO 4217 alphabetic codes. */
    private const CODES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
        'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
        'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
        'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF',
        'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
        'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR',
        'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
        'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR',
        'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD',
        'SHP', 'SLE', 'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL',
        'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
        'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD',
        'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWL',
    ];

    /** @return string[] */
    public static function codes(): array
    {
        return self::CODES;
    }

    public static function isValid(?string $code): bool
    {
        return in_array(strtoupper(trim((string) $code)), self::CODES, true);
    }

    /**
     * Best guess for a mistyped code (transposed or one wrong letter), e.g.
     * "DBT" → "BDT", "NGM" → "NGN". Null when nothing is close enough.
     */
    public static function suggest(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        if (strlen($code) !== 3 || self::isValid($code)) {
            return null;
        }

        // Same letters, different order (the most common slip) wins first.
        $sorted = str_split($code);
        sort($sorted);
        foreach (self::CODES as $candidate) {
            $c = str_split($candidate);
            sort($c);
            if ($c === $sorted) {
                return $candidate;
            }
        }

        foreach (self::CODES as $candidate) {
            if (levenshtein($code, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Human-readable validation message for an invalid code, with a
     * "did you mean" hint when one is available.
     */
    public static function invalidMessage(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        $suggestion = self::suggest($code);

        return $suggestion
            ? __('central.InvalidCurrencyCodeSuggest', ['code' => $code, 'suggestion' => $suggestion])
            : __('central.InvalidCurrencyCode', ['code' => $code]);
    }

    /**
     * Laravel validation closure: `'currency_code' => [..., IsoCurrencies::rule()]`.
     */
    public static function rule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && ! self::isValid((string) $value)) {
                $fail(self::invalidMessage((string) $value));
            }
        };
    }
}
