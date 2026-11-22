<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted-at-rest string that still reads values stored before encryption
 * was introduced (plaintext rows keep working and are encrypted on the next
 * save / by the backfill migration).
 *
 * Unlike Laravel's built-in "encrypted" cast it never throws on a plaintext
 * value. A value that IS an encrypted payload but cannot be decrypted (APP_KEY
 * rotated) reads as null instead of leaking ciphertext into API calls.
 *
 * Note: query-builder writes (Model::whereId()->update([...])) bypass casts —
 * use self::encrypt() there.
 */
class EncryptedOrPlain implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        return self::decrypt($value);
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return self::encrypt($value);
    }

    public static function encrypt($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = (string) $value;

        // Already encrypted (e.g. re-saving a raw attribute): keep as-is.
        if (self::looksEncrypted($value)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    public static function decrypt($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = (string) $value;

        if (! self::looksEncrypted($value)) {
            return $value; // legacy plaintext
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Laravel encrypter payload: base64 JSON with iv/value/mac. */
    public static function looksEncrypted(string $value): bool
    {
        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
