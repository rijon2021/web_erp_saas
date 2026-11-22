<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/**
 * System Settings → Mobile App → "Mobile app enabled" switched off: refuse
 * every call the Flutter app makes, not just new sign-ins, so devices that
 * are already signed in stop at the next request.
 *
 * The app marks its calls with `X-Stocky-Client: mobile`; the SPA, the
 * public REST API and the storefront never send it and are not affected.
 * This is an operational switch, not a security boundary — the same users
 * can still sign in on the web.
 *
 * ping/login/logout stay reachable: ping carries the message the app shows,
 * login already answers AppDisabled itself, and logout must always work.
 */
class EnsureMobileAppEnabled
{
    private const OPEN_PATHS = ['api/mobile/ping', 'api/mobile/login', 'api/mobile/logout'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->header('X-Stocky-Client') !== 'mobile' || $request->is(self::OPEN_PATHS)) {
            return $next($request);
        }

        $setting = $this->setting();
        if ($setting && ! (bool) ($setting->mobile_app_enabled ?? true)) {
            return response()->json([
                'status' => 'AppDisabled',
                'message' => self::message($setting),
            ], 403);
        }

        return $next($request);
    }

    /** Text shown in the app while it is switched off. */
    public static function message(?Setting $setting): string
    {
        return ($setting->mobile_maintenance_message ?? null)
            ?: 'The mobile app is currently unavailable. Please contact your administrator.';
    }

    private function setting(): ?Setting
    {
        try {
            return Setting::whereNull('deleted_at')
                ->first(['id', 'mobile_app_enabled', 'mobile_maintenance_message']);
        } catch (\Throwable $e) {
            // Mobile-app migration not run on this workspace yet: the app stays on.
            return null;
        }
    }
}
