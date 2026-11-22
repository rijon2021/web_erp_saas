<?php

namespace App\Services\LicenseKeys;

use App\Models\LicenseKey;

/**
 * Makes unique keys from a format: every X becomes a random character from an
 * unambiguous alphabet (no 0/O, 1/I/L), every 9 a digit; anything else is
 * kept as typed. Default format: XXXXX-XXXXX-XXXXX-XXXXX.
 */
class LicenseKeyGenerator
{
    public const DEFAULT_FORMAT = 'XXXXX-XXXXX-XXXXX-XXXXX';

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const DIGITS = '23456789';

    /** Minimum randomness of a key: X ≈ 4.95 bits (31 symbols), 9 = 3 bits (8 digits). */
    public const MIN_BITS = 60;

    /** A format is usable when its random positions carry at least MIN_BITS of entropy. */
    public static function validFormat(?string $format): bool
    {
        if ($format === null || $format === '') {
            return true;
        }
        if (mb_strlen($format) > 64) {
            return false;
        }

        return self::bits($format) >= self::MIN_BITS;
    }

    public static function bits(string $format): float
    {
        return substr_count(strtoupper($format), 'X') * log(strlen(self::ALPHABET), 2)
            + substr_count($format, '9') * log(strlen(self::DIGITS), 2);
    }

    public function make(?string $format = null): string
    {
        $format = trim((string) $format) !== '' ? (string) $format : self::DEFAULT_FORMAT;
        $out = '';
        foreach (mb_str_split($format) as $ch) {
            if ($ch === 'X' || $ch === 'x') {
                $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            } elseif ($ch === '9') {
                $out .= self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
            } else {
                $out .= mb_strtoupper($ch);
            }
        }

        return $out;
    }

    /** A key not yet in license_keys (retries on the rare collision). */
    public function unique(?string $format = null): string
    {
        for ($i = 0; $i < 20; $i++) {
            $key = $this->make($format);
            $hash = LicenseKey::hashKey(LicenseKey::normalize($key, true));
            if (! LicenseKey::where('key_hash', $hash)->exists()) {
                return $key;
            }
        }

        throw new \RuntimeException('Could not generate a unique license key — widen the key format.');
    }
}
