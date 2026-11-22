<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every Laravel response.
 *
 *  - X-Content-Type-Options: nosniff — no MIME sniffing of responses.
 *  - X-Frame-Options / CSP frame-ancestors 'self' — the admin, POS, portal
 *    and storefront cannot be framed by other sites (clickjacking). The CRM
 *    lead form (lead-form/*) is exempt: it is MEANT to be iframed elsewhere.
 *  - Referrer-Policy — no full URLs (tokens, ids) leaked to other sites.
 *  - Strict-Transport-Security — only on HTTPS requests, so plain-HTTP
 *    installs are never locked out.
 *
 * Headers a controller already set are left alone. A full script CSP is not
 * sent: the Blade views rely on inline scripts.
 */
class SetSecurityHeaders
{
    /** Routes other sites may embed in an iframe. */
    private const FRAMEABLE = ['lead-form/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $this->setIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $request->is(...self::FRAMEABLE)) {
            $this->setIfMissing($response, 'X-Frame-Options', 'SAMEORIGIN');

            if (! $headers->has('Content-Security-Policy')) {
                $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
            }
        }

        if ($request->isSecure()) {
            $this->setIfMissing($response, 'Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function setIfMissing(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
