<?php

namespace App\Services\Couriers\Support;

/** Phone formats couriers insist on. */
final class Phone
{
    /**
     * Pakistani mobile in local form 03XXXXXXXXX (11 digits), from
     * "+92 300 1234567", "923001234567", "3001234567", "0300-1234567".
     * Anything that does not look like a Pakistani mobile is returned as
     * digits only, so the courier's own validation can report it.
     */
    public static function pk(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($digits, '0092')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    /**
     * Digits with the country code, e.g. intl('0555 123 456', '966') →
     * 966555123456. A local leading 0 is dropped; numbers already carrying the
     * code (or 00 + code) are kept; a bare national number gets the code.
     */
    public static function intl(?string $phone, string $countryCode): string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return '';
        }
        // Written in international form (+… / 00…): trust it as is.
        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
        }
        // Starts with the code AND is long enough to hold a national number after it:
        // a local number that merely begins with the same digits (Brazil area 55,
        // a Singapore 65xx landline) still gets the code.
        $national = strlen($digits) - strlen($countryCode);
        if (! str_starts_with($digits, '0') && str_starts_with($digits, $countryCode) && $national >= (self::MIN_NATIONAL[$countryCode] ?? 8)) {
            return $digits;
        }

        return $countryCode . ltrim($digits, '0');
    }

    /** Shortest national number (without trunk 0) per calling code; default 8. */
    private const MIN_NATIONAL = [
        '966' => 9, '971' => 9, '965' => 8, '973' => 8, '974' => 8, '968' => 8, '20' => 10,
        '65' => 8, '60' => 9, '63' => 10, '66' => 9, '62' => 9, '84' => 9, '852' => 8, '886' => 9,
        '55' => 10, '52' => 10, '81' => 10, '234' => 10, '92' => 10, '880' => 10, '91' => 10, '27' => 9,
    ];

    /** Bangladeshi mobile 01XXXXXXXXX (from +880…, 880…, 1XXXXXXXXX). */
    public static function bd(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    /** Nigerian number in local form 0XXXXXXXXXX (11 digits), from +234…, 234…, 8XXXXXXXXX. */
    public static function ng(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '00234')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '234') && (strlen($digits) === 13 || (strlen($digits) === 14 && $digits[3] === '0'))) {
            // 234…, and "+234 (0) 803…" written with the trunk 0.
            $digits = substr($digits, 3);
        }
        if (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    /** International form 923XXXXXXXXX. */
    public static function pkIntl(?string $phone): string
    {
        $local = self::pk($phone);

        return strlen($local) === 11 && str_starts_with($local, '0') ? '92' . substr($local, 1) : $local;
    }
}
