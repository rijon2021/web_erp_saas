<?php

namespace App\Http\Middleware;

use App\Support\Security\RevokeUserAccess;
use Closure;
use Illuminate\Support\Facades\Auth;

class Is_Active
{
    /**
     * Refuse requests from deactivated or deleted users.
     *
     * The check runs BEFORE the request is handled (it used to run after, so a
     * disabled user's sale/edit/export had already been committed), and on the
     * guard that authenticated this request — `auth:api` (Passport bearer
     * token or laravel_token cookie) makes that the default guard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if (RevokeUserAccess::isDisabled($user)) {
            // Kill this credential and any others the user still holds.
            RevokeUserAccess::all((int) $user->id);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Your account has been disabled.',
                    'status' => 'AccountDisabled',
                ], 401);
            }

            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/login')->with('erro_login', 'Your account has been disabled.');
        }

        return $next($request);
    }
}
