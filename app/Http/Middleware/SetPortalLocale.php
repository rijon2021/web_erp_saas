<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Client-portal locale resolution. Runs after tenancy is initialized (the
 * tenant route group) and overrides the app locale for portal requests only,
 * so the portal keeps its own language even though it shares the session
 * cookie with the admin app and storefront.
 *
 * Precedence, highest first:
 *
 *   1. session(SESSION_KEY)   — the header switcher, an explicit choice this session
 *   2. the signed-in portal client's saved preference (follows them across devices)
 *   3. cookie(COOKIE_NAME)    — a choice made on this device before signing in
 *   4. Portal Settings default language (Client Portal → Portal Settings)
 *   5. config('app.locale')
 *
 * The supported set is the ACTIVE rows of portal_languages (Client Portal →
 * Portal Languages); falls back to en/fr/ar/es before the tables exist.
 */
class SetPortalLocale
{
    public const SESSION_KEY = 'portal_locale';

    public const COOKIE_NAME = 'portal_locale';

    public function handle($request, Closure $next)
    {
        $supported = \App\Services\PortalLanguageInstaller::activeLocales();

        $candidates = [
            Session::get(self::SESSION_KEY),
            $this->savedPreference(),
            $request->cookie(self::COOKIE_NAME),
            \App\Support\PortalSettings::defaultLocale(),
            config('app.locale'),
        ];

        foreach ($candidates as $locale) {
            if ($locale && isset($supported[$locale])) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    private function savedPreference(): ?string
    {
        try {
            return optional(Auth::guard('portal')->user())->preferred_locale ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
