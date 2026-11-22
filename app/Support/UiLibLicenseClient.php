<?php

/**
 * UI Lib licence client -- reference implementation.
 *
 * Drop this file into a product (every UI Lib product is a Laravel app;
 * the class itself is plain PHP) and call it from the product's backend. Plain PHP 7.4+, needs
 * ext-curl and ext-sodium (bundled with PHP since 7.2). No dependencies.
 *
 *   $client = new UiLibLicenseClient(
 *       'https://ui-lib.com/wp-json/ul-license/v1/',
 *       '<public key from the licensing guide, section 1>',
 *       'stocky-pos'                       // this product's ID
 *   );
 *   $result = $client->activate($license, $email, $siteUrl);
 *
 * Every method returns the same array:
 *
 *   ok            bool    true only for a 200 whose token verified
 *   http          int     HTTP status (0 on a network error)
 *   network_error bool    could not reach the server -- keep the cached state
 *   code          ?string error code (see the licensing guide), or 'bad_signature'
 *   message       ?string customer-facing message for errors
 *   data          ?array  the answer: status, domain, license{...}, ... (on
 *                         errors, extra details such as `domains` for
 *                         activation_limit)
 *   token         ?string signed token -- store it; verifyToken() it later
 *
 * Source of truth: the UI Lib licensing integration guide. Use this
 * file unchanged; ask the ui-lib.com team for a newer copy, never edit it.
 */
final class UiLibLicenseClient
{
    /** @var string */
    private $endpoint;

    /** @var string raw 32-byte Ed25519 public key */
    private $publicKey;

    /** @var string */
    private $product;

    /** @var int */
    private $timeout;

    public function __construct(string $endpoint, string $publicKeyBase64, string $product, int $timeout = 15)
    {
        $this->endpoint = rtrim($endpoint, '/') . '/';
        $this->publicKey = (string) base64_decode($publicKeyBase64, true);
        $this->product = $product;
        $this->timeout = $timeout;
    }

    /**
     * Activate on this install's domain. Idempotent.
     *
     * @param string $license Licence key (ULK-…) or Envato purchase code.
     * @param string $email   Required for Envato codes; pass '' for keys.
     * @param string $siteUrl The app's configured base URL (APP_URL, i.e. config('app.url')).
     * @param array  $extra   Optional: version, instance_id.
     */
    public function activate(string $license, string $email, string $siteUrl, array $extra = []): array
    {
        return $this->call('activate', $license, $email, $siteUrl, $extra);
    }

    /**
     * Status on this domain, without changing anything.
     */
    public function check(string $license, string $email, string $siteUrl, array $extra = []): array
    {
        return $this->call('check', $license, $email, $siteUrl, $extra);
    }

    /**
     * Release a domain: this install's own, or any other domain of the
     * licence (pass that domain instead of the site URL).
     */
    public function deactivate(string $license, string $email, string $domainOrUrl): array
    {
        return $this->call('deactivate', $license, $email, $domainOrUrl, []);
    }

    /**
     * Verify a stored token: genuine, for this product, for this domain,
     * not expired.
     *
     * The product check matters: without it, a token another UI Lib
     * product earned on the same domain (a cheaper one, say) could be
     * copied into this product's settings and pass.
     *
     * @return array|null The signed data, or null if it cannot be trusted.
     */
    public function verifyToken(string $token, string $siteUrl): ?array
    {
        if (32 !== strlen($this->publicKey) || false === strpos($token, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        $json = self::b64urlDecode($payload);
        $sig = self::b64urlDecode($signature);

        if ('' === $json || 64 !== strlen($sig) || !sodium_crypto_sign_verify_detached($sig, $json, $this->publicKey)) {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)
            || ($data['license']['product'] ?? null) !== $this->product
            || ($data['domain'] ?? null) !== self::normalizeDomain($siteUrl)
            || (int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    /**
     * Whether the email field is needed: anything that is not a ui-lib.com
     * licence key is treated as an Envato purchase code.
     */
    public static function needsEmail(string $license): bool
    {
        return 0 !== stripos(trim($license), 'ULK-');
    }

    /**
     * The host as the server stores it: lower case, no port, no trailing
     * dot, no leading "www.".
     */
    public static function normalizeDomain(string $url): ?string
    {
        $url = strtolower(trim($url));
        if ('' === $url) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#', $url)) {
            $url = 'http://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return null;
        }
        $host = rtrim(trim($host, '[]'), '.');
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = false === $ascii ? $host : strtolower($ascii);
        }
        return 0 === strpos($host, 'www.') ? substr($host, 4) : $host;
    }

    private function call(string $action, string $license, string $email, string $domain, array $extra): array
    {
        $body = [
            'product' => $this->product,
            'license' => trim($license),
            'email' => trim($email),
            'domain' => $domain,
        ];
        foreach (['version', 'instance_id'] as $key) {
            if (isset($extra[$key])) {
                $body[$key] = (string) $extra[$key];
            }
        }

        $ch = curl_init($this->endpoint . $action);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $result = ['ok' => false, 'http' => $http, 'network_error' => false, 'code' => null, 'message' => null, 'data' => null, 'token' => null];

        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (false === $raw || 0 === $http || !is_array($json)) {
            // Unreachable, timed out, or not our JSON (a proxy error page):
            // never a reason to drop a licence.
            $result['network_error'] = true;
            return $result;
        }

        if (empty($json['success'])) {
            $result['code'] = $json['code'] ?? 'error';
            $result['message'] = $json['message'] ?? null;
            $result['data'] = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
            // Server trouble and throttling are transient, like a network error.
            $result['network_error'] = $http >= 500 || 'rate_limited' === $result['code'];
            return $result;
        }

        $verified = $this->verifyToken((string) ($json['token'] ?? ''), $domain);

        if (null === $verified) {
            $result['code'] = 'bad_signature';
            $result['message'] = 'The licence server\'s answer could not be verified.';
            return $result;
        }

        $result['ok'] = true;
        $result['data'] = $verified;
        $result['token'] = (string) $json['token'];
        return $result;
    }

    private static function b64urlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return false === $decoded ? '' : $decoded;
    }
}
