<?php

namespace App\Support\Security;

/**
 * RFC 6238 time-based one-time passwords (6 digits, 30-second steps,
 * HMAC-SHA1) — the variant every authenticator app supports: Google
 * Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden…
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new 160-bit secret, base32-encoded (32 characters). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The otpauth:// link the QR code carries. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer = str_replace(':', '', $issuer);
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The time step the code matches, or null. One step either side is
     * accepted so a phone clock up to ~30 s off still works. The caller
     * rejects a step at or before the last one used (replay protection).
     */
    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }

        $step = intdiv($time ?? time(), self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::at($key, $step + $offset), $code)) {
                return $step + $offset;
            }
        }

        return null;
    }

    public static function at(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=-]/', '', $secret));

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
