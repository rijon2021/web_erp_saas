<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trust X-Forwarded-* only from the proxies listed in TRUSTED_PROXIES.
 *
 * Behind a load balancer / Cloudflare / nginx reverse proxy, set e.g.
 *   TRUSTED_PROXIES=10.0.0.1,10.0.0.2   (or * when the app is ONLY reachable
 *                                         through the proxy)
 * so $request->ip() (login lockout, audit log, device list) and
 * isSecure() (HSTS, secure cookies) see the real client.
 *
 * Unset (default) = trust nobody: a direct client cannot spoof its IP with
 * an X-Forwarded-For header to dodge the login lockout.
 */
class TrustProxies extends Middleware
{
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        // Read via config (env() is null once `config:cache` has run).
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            $this->proxies = null;
        } elseif ($configured === '*') {
            $this->proxies = '*';
        } else {
            $this->proxies = array_values(array_filter(array_map('trim', explode(',', $configured))));
        }
    }
}
