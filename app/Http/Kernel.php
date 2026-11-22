<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middlewarePriority = [
        // REST API key auth must bind the user before ThrottleRequests asks
        // for one: otherwise Passport tries the stk_ key as an OAuth token,
        // fails, and clears the Authorization header the key arrived in.
        \App\Http\Middleware\ApiKeyAuth::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\Authenticate::class,
        // \Modules\Store\Http\Middleware\Authenticate::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
        // \App\Http\Middleware\AddAuthHeader::class,
    ];

    protected $middleware = [
        // Trusts X-Forwarded-* only from TRUSTED_PROXIES (config app.trusted_proxies;
        // empty = nobody), so the real client IP/scheme is seen behind a proxy
        // without letting direct clients spoof them.
        \App\Http\Middleware\TrustProxies::class,
        // nosniff / frame-ancestors / Referrer-Policy / HSTS (HTTPS only) on every
        // response, central and tenant alike. lead-form/* stays frameable.
        \App\Http\Middleware\SetSecurityHeaders::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \App\Http\Middleware\ServeSetupWhenNotInstalled::class,
        \App\Http\Middleware\EnsurePlatformLicensed::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\SetSessionConfig::class,

    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EnsureNotUpdating::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,

            \App\Http\Middleware\SetLocale::class,

            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SafeCreateFreshApiToken::class,
            // Settings > Login Devices: boot a device out of the Blade shell once
            // its session has been revoked from that screen.
            \App\Http\Middleware\LogoutRevokedWebSession::class,

        ],

        'store' => [
            // ... other middlewares
            'store.auth',
        ],

        'api' => [
            // 'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SetPdfLocale::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'store.auth' => \App\Http\Middleware\StoreAuthenticate::class,
        'store.checkout' => \App\Http\Middleware\StoreCheckoutAccess::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'Is_Active' => \App\Http\Middleware\Is_Active::class,
        'store.data' => \App\Http\Middleware\StoreDataMiddleware::class,
        'setlocale' => \App\Http\Middleware\SetLocale::class,
        'XSS' => \App\Http\Middleware\XSS::class,
        'request.safety' => \App\Http\Middleware\RequestSafety::class,
        'store.enabled' => \App\Http\Middleware\EnsureStoreEnabled::class,
        // Security: inactivity auto-logout + token activity tracking (Passport)
        'token.timeout' => \App\Http\Middleware\EnforceApiTokenTimeout::class,
        'pdf.locale' => \App\Http\Middleware\SetPdfLocale::class,
        // Zapier integration: per-tenant API key auth
        'zapier.auth' => \App\Http\Middleware\ZapierAuth::class,
        // Public REST API (/api/v1): key auth + Idempotency-Key replay.
        'apikey.auth' => \App\Http\Middleware\ApiKeyAuth::class,
        'api.idempotent' => \App\Http\Middleware\ApiIdempotency::class,
        // Client portal: separate auth from admin/store
        'portal.auth' => \App\Http\Middleware\EnsurePortalAuth::class,
        'portal.locale' => \App\Http\Middleware\SetPortalLocale::class,
        'portal.feature' => \App\Http\Middleware\EnsurePortalFeature::class,
        // Central (SaaS) super admin
        'auth.central' => \App\Http\Middleware\EnsureCentralAuth::class,
        'central.permission' => \App\Http\Middleware\CheckCentralPermission::class,
        // Super-admin mobile app API (/api/super/*)
        'auth.central.api' => \App\Http\Middleware\AuthenticateCentralApi::class,
        'central.api.permission' => \App\Http\Middleware\CentralApiPermission::class,
        // Tenant: block suspended/cancelled
        'tenant.active' => \App\Http\Middleware\EnsureTenantNotSuspended::class,
        // Tenant: enforce plan usage limits (e.g. max_products, max_users)
        'tenant.limit' => \App\Http\Middleware\CheckTenantLimits::class,
        // Tenant: check feature availability on current plan
        'tenant.feature' => \App\Http\Middleware\CheckTenantFeature::class,
        // Tenant: require active subscription
        'tenant.subscribed' => \App\Http\Middleware\EnsureActiveSubscription::class,
        // Tenant: pause outward-facing services (portal, Zapier) when no subscription grants access
        'tenant.entitled' => \App\Http\Middleware\EnsureTenantEntitled::class,
        // Tenant: track last activity timestamp
        'tenant.activity' => \App\Http\Middleware\TrackTenantActivity::class,

    ];
}
