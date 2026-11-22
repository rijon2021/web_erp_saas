<?php

namespace App\Services\Agt;

use RuntimeException;

/**
 * JWS compact serialization with RS256, as the AGT e-invoicing API
 * requires: header {"alg":"RS256","typ":"JWT"}, payload = the whole object
 * as canonical JSON (no whitespace), Base64URL without padding.
 */
class Jws
{
    public static function sign(array $payload, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('The RSA private key could not be read (expected a PEM "BEGIN PRIVATE KEY" / "BEGIN RSA PRIVATE KEY" block).');
        }

        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new RuntimeException('AGT requires an RSA private key of at least 2048 bits.');
        }

        $signingInput = self::b64(self::canonical(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.self::b64(self::canonical($payload));

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('RS256 signing failed: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return $signingInput.'.'.self::b64($signature);
    }

    /** Validate a PEM private key without signing anything. Returns the key size in bits. */
    public static function inspect(string $privateKeyPem): int
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('The RSA private key could not be read.');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The key is not an RSA key.');
        }

        return (int) ($details['bits'] ?? 0);
    }

    /** Public key (PEM) of a private key — what the producer registers with AGT. */
    public static function publicKey(string $privateKeyPem): ?string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        return $key ? (openssl_pkey_get_details($key)['key'] ?? null) : null;
    }

    /**
     * New RSA private key (PEM). Windows PHP builds often have no default
     * openssl.cnf, so fall back to OPENSSL_CONF or the one shipped with PHP.
     */
    public static function generatePrivateKey(int $bits = 4096): string
    {
        $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $configs = array_filter([
            null,
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        ], fn ($c) => $c === null || is_file($c));

        foreach ($configs as $config) {
            $opts = $config ? $options + ['config' => $config] : $options;
            $key = @openssl_pkey_new($opts);
            if ($key !== false && @openssl_pkey_export($key, $pem, null, $config ? ['config' => $config] : null)) {
                return $pem;
            }
        }

        throw new RuntimeException('RSA key generation failed: '.(openssl_error_string() ?: 'no usable openssl.cnf'));
    }

    public static function canonical(array $data): string
    {
        // Amounts are normalised by AgtDocumentBuilder::amount() (int when
        // whole, 2 decimals otherwise), so the signed bytes match the payload.
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
