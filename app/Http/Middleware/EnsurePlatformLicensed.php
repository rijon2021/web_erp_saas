<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Locks the whole platform (central admin, tenant apps, storefronts, APIs)
 * while the UI Lib licence is not active. Decided offline from the stored
 * signed token (LicenseService::lockState()), so no request ever waits on
 * ui-lib.com.
 *
 * Reachable while locked: the setup wizard, the super admin login/logout and
 * password routes, and Settings → Licence, where the owner re-activates.
 * The list is OPEN_PATHS below. Artisan commands and queue
 * jobs are not affected: only HTTP is locked.
 *
 * A super admin URL gets a page that names the licence and links to the
 * Licence page; everything else (tenants, shoppers, API clients) gets a
 * neutral "temporarily unavailable" answer with HTTP 503.
 */
class EnsurePlatformLicensed
{
    /**
     * Reachable while locked. In code, not in config/uilib.php: a cached
     * config from before this release would leave this list empty and shut
     * the owner out of the login and the Licence page.
     */
    private const OPEN_PATHS = [
        'setup', 'setup/*', 'assets_setup/*',
        'super/login', 'super/login/*', 'super/logout',
        'super/forgot-password', 'super/forgot-password/*', 'super/reset-password*', 'super/locale/*',
        'super/settings/license', 'super/settings/license/*',
        // The updater drives itself over HTTP: an install upgrading to the
        // first licensed release has no licence yet, and its remaining steps
        // must still run. Recovery lives here too. All behind the admin login.
        'update', 'update/*', 'super/system-update', 'super/system-update/*',
        // Signed inbound callbacks from payment gateways, channels, couriers
        // and WhatsApp: providers stop retrying after a few days, so a lock
        // must not lose payments that were really made.
        'webhook/*', 'whatsapp/webhook/*',                                   // central: billing gateways, WhatsApp
        'api/webhooks/incoming/*', 'api/shopify/webhook', 'api/salla/webhook',
        'api/couriers/webhook/*', 'api/printful/webhook/*', 'api/opencart/webhook/*',
        'api/store/sslcommerz/ipn', 'api/store/paymob/webhook', 'api/store/xpay/webhook', 'api/store/webhooks/*',
        'billing/callback/*',                                                // tenant subscription payment return
        // Shoppers coming back from a gateway: bKash has no webhook, so a paid
        // order is only confirmed by this return.
        'api/store/bkash/return', 'api/store/sslcommerz/*',
        'pay/*/return', 'pay/*/cancel',                                      // central payment links
        // Storefront callbacks, with or without the store's URL prefix.
        'payfast/itn', 'ligdicash/callback', 'dodo/webhook', 'waafipay/webhook',
        '*/payfast/itn', '*/ligdicash/callback', '*/dodo/webhook', '*/waafipay/webhook',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->is(...self::OPEN_PATHS)) {
            return $next($request);
        }

        $this->refreshAfterResponse($request);

        try {
            $lock = app(LicenseService::class)->lockState();
        } catch (\Throwable $e) {
            // The licence must never take the platform down by accident.
            return $next($request);
        }

        if (($lock['state'] ?? LicenseService::LOCK_OPEN) !== LicenseService::LOCK_LOCKED) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => __('super.license.locked_public_text')], 503);
        }

        $central = in_array($request->getHost(), array_map('strtolower', (array) config('tenancy.central_domains', [])), true);

        return response()->view('central.license_locked', [
            'super' => $central && ($request->is('super') || $request->is('super/*')),
        ], 503);
    }

    /**
     * The cron-free daily re-check. Hung on the middleware so any visitor
     * keeps the token fresh, staff or not, and a licence that lapsed in an
     * outage reopens by itself once ui-lib.com answers again. At most once
     * an hour, on a GET only, so nothing waits behind a sale or a failing
     * server. No cache means no lazy check: the scheduler still covers it.
     */
    private function refreshAfterResponse(Request $request): void
    {
        try {
            if ($request->isMethod('GET') && Cache::add('uilib:license:lazy_check', 1, 3600)) {
                app()->terminating(fn () => app(LicenseService::class)->refreshIfStale());
            }
        } catch (\Throwable $e) {
            // the licence must never break a request
        }
    }
}
