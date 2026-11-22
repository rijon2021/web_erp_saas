<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Checks whether a custom domain already serves a valid HTTPS certificate.
 *
 * The platform never issues certificates itself — the web server (Let's
 * Encrypt / certbot / the hosting panel) or a proxy such as Cloudflare does.
 * This probe only reports what a browser would see on https://<domain>:
 *
 *   1. A strict TLS handshake (trusted chain + host name, with SNI). Passing
 *      it means the padlock works  → ok.
 *   2. When it fails, a second, permissive handshake fetches whatever
 *      certificate is served, to explain why:
 *        unreachable    nothing answers on port 443 / handshake impossible
 *        name_mismatch  a certificate for another host (typically the
 *                       platform's own *.central-domain wildcard — the server
 *                       routes the domain but has no certificate for it)
 *        expired        the right certificate, past its end date
 *        untrusted      the right name, but self-signed / incomplete chain
 *
 * @phpstan-type ProbeResult array{ok: bool, reason: string, detail: string, names: string[], expires_in_days: int|null}
 */
class CustomDomainSslProbe
{
    public const OK             = 'ok';
    public const UNREACHABLE    = 'unreachable';
    public const NAME_MISMATCH  = 'name_mismatch';
    public const EXPIRED        = 'expired';
    public const UNTRUSTED      = 'untrusted';

    private const TIMEOUT = 6;

    /** @return ProbeResult */
    public function probe(string $domain): array
    {
        $result = $this->probeOnce($domain);

        // A network stall mid-handshake looks like "unreachable" or "untrusted";
        // one blip must not flip a working domain to failed — retry once.
        if (! $result['ok']) {
            usleep(500_000);
            $result = $this->probeOnce($domain);
        }

        return $result;
    }

    /** @return ProbeResult */
    private function probeOnce(string $domain): array
    {
        $strict = $this->handshake($domain, true);
        if ($strict['cert'] !== null) {
            $info = openssl_x509_parse($strict['cert']) ?: [];

            return $this->result(true, self::OK, '', $this->names($info), $this->daysLeft($info));
        }

        $loose = $this->handshake($domain, false);
        if ($loose['cert'] === null) {
            return $this->result(false, self::UNREACHABLE, $loose['error'] ?: $strict['error']);
        }

        $info  = openssl_x509_parse($loose['cert']) ?: [];
        $names = $this->names($info);
        $days  = $this->daysLeft($info);

        if (! $this->matches($domain, $names)) {
            return $this->result(false, self::NAME_MISMATCH, implode(', ', $names), $names, $days);
        }

        if ($days !== null && $days < 0) {
            return $this->result(false, self::EXPIRED, '', $names, $days);
        }

        return $this->result(false, self::UNTRUSTED, $strict['error'], $names, $days);
    }

    /** @return array{cert: mixed, error: string} */
    private function handshake(string $domain, bool $verify): array
    {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => ! $verify,
            'peer_name'         => $domain,
            'SNI_enabled'       => true,
        ]]);

        $error = '';
        set_error_handler(static function (int $no, string $msg) use (&$error): bool {
            $error = trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $msg) ?? $msg);

            return true;
        });

        try {
            $fp = stream_socket_client("ssl://{$domain}:443", $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        } catch (\Throwable $e) {
            $fp = false;
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }

        if (! $fp) {
            return ['cert' => null, 'error' => $error !== '' ? $error : (string) ($errstr ?? '')];
        }

        $params = stream_context_get_params($fp);
        fclose($fp);

        return ['cert' => $params['options']['ssl']['peer_certificate'] ?? null, 'error' => $error];
    }

    /** Host names the certificate covers (SANs, falling back to the CN). @return string[] */
    private function names(array $info): array
    {
        $names = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        if (! $names && ! empty($info['subject']['CN'])) {
            $names[] = strtolower((string) (is_array($info['subject']['CN']) ? end($info['subject']['CN']) : $info['subject']['CN']));
        }

        return array_values(array_unique($names));
    }

    /** @param string[] $names */
    private function matches(string $domain, array $names): bool
    {
        $domain = strtolower($domain);

        foreach ($names as $name) {
            if ($name === $domain) {
                return true;
            }
            // "*.example.com" covers exactly one extra label.
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                $label  = substr($domain, 0, -strlen($suffix));
                if (str_ends_with($domain, $suffix) && $label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function daysLeft(array $info): ?int
    {
        $validTo = $info['validTo_time_t'] ?? null;

        return $validTo ? (int) floor(((int) $validTo - time()) / 86400) : null;
    }

    /** @return ProbeResult */
    private function result(bool $ok, string $reason, string $detail, array $names = [], ?int $days = null): array
    {
        return [
            'ok'              => $ok,
            'reason'          => $reason,
            'detail'          => mb_substr($detail, 0, 300),
            'names'           => $names,
            'expires_in_days' => $days,
        ];
    }
}
