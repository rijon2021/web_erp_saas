<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Security\ForceLogout;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;

class Authenticate extends Middleware
{
    /**
     * Every auth:api route passes here, including the groups without
     * token.timeout, so this is where a super admin "force logout" refuses the
     * SPA's still-valid Passport cookie. The 409 makes the SPA log out.
     */
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        if ($this->auth->getDefaultDriver() !== 'api') {
            return;
        }

        $user = $this->auth->guard()->user();
        if ($user instanceof User && ForceLogout::apiCookieIsStale($request, $user)) {
            throw new HttpResponseException(
                response()->json(['message' => 'You were signed out by an administrator.'], 409)
                    ->header('X-Session-Revoked', '1')
            );
        }
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // If setup not completed, always redirect to setup (use path so it works when route name not loaded)
        // On tenant domains the tenancy filesystem bootstrapper changes disk roots,
        // so the "installed" marker must be checked via absolute path.
        if (! file_exists(base_path('storage/app/public/installed'))) {
            return '/setup';
        }

        // Handle Online Store routes (default /online_store or the custom base path)
        if (is_store_request($request)) {
            // If store expects JSON (API calls), don’t redirect — return 401
            if ($request->expectsJson()) {
                return null;
            }

            // Redirect to the store login page. In root-domain mode it lives
            // under /customer/login so it never shadows the staff /login.
            try {
                return route('store.login.show');
            } catch (\Throwable $e) {
                return store_auth_url('login');
            }
        }

        // Client portal API: separate auth — never redirect to admin login; return 401
        if ($request->is('api/portal') || $request->is('api/portal/*')) {
            return null;
        }

        // Default for admin panel or web
        if (! $request->expectsJson()) {
            return route('login');
        }

        return null; // For API calls (JSON)
    }
}
