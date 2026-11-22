<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    public function handle($request, Closure $next)
    {
        // The portal API is token-exempt (tokens go stale across tabs/cached
        // pages) but session-authenticated, so it still needs CSRF protection:
        // require the X-Requested-With header the portal client always sends
        // (resources/src/portal/lib/http.js). A cross-site form cannot set
        // custom headers, and a cross-site fetch that does triggers a CORS
        // preflight this app never approves.
        if (! $request->isMethodSafe() && $request->is('api/portal', 'api/portal/*')
            && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
            return response()->json(['message' => 'CSRF check failed.'], 419);
        }

        return parent::handle($request, $next);
    }

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Setup wizard: runs before app is fully configured; sessions/cookies may not
        // be reliable yet. One-time operation on fresh install, no security risk.
        'setup',
        'setup/*',
        // Central (super admin) login can hit session cookie edge-cases on some local setups
        // (Laragon, custom hosts). Exclude only the login submit endpoint.
        'super/login',
        // Client portal API: session-based auth; CSRF token can mismatch when portal
        // is opened in another tab or page is cached. SameSite session cookie is used.
        'api/portal',
        'api/portal/*',
        // Payment gateway webhooks: signed by the gateway; no session/CSRF context.
        'webhook/*',
        // PayFast ITN callback (tenant storefront): signed + server-validated; no session/CSRF context.
        'online_store/payfast/itn',
        // LigdiCash callback (tenant storefront): re-verified via confirm endpoint; no session/CSRF context.
        'online_store/ligdicash/callback',
        // Dodo Payments webhook (tenant storefront): Standard Webhooks signature; no session/CSRF context.
        'online_store/dodo/webhook',
        // WaafiPay webhook (tenant storefront): HMAC signature; no session/CSRF context.
        'online_store/waafipay/webhook',
        // WhatsApp Cloud API webhook: signed by Meta (X-Hub-Signature-256); no session/CSRF context.
        'whatsapp/webhook/*',
        // Payment link gateway return/cancel legs (some gateways POST back cross-site).
        // Read-only on the request: the attempt is re-verified with the gateway by stored reference.
        'pay/*/return',
        'pay/*/cancel',
        // CRM web forms are embedded in other sites' iframes, where the session
        // cookie is not sent; they are guarded by a throttle, a honeypot and a
        // signed timestamp instead (CrmWebFormPublicController).
        'lead-form/*',
    ];

    /**
     * Gateway callbacks under the storefront base path. The static list above
     * covers the default /online_store; a tenant with a custom store path gets
     * the same exclusions under that path.
     */
    protected const STORE_CALLBACKS = ['payfast/itn', 'ligdicash/callback', 'dodo/webhook', 'waafipay/webhook'];

    protected function inExceptArray($request)
    {
        if (parent::inExceptArray($request)) {
            return true;
        }

        foreach (static::STORE_CALLBACKS as $callback) {
            // store_path_to() keeps this correct in root-domain mode, where the
            // base path is '' and a naive concat would give a leading slash.
            if ($request->is(store_path_to($callback))) {
                return true;
            }
        }

        return false;
    }
}
