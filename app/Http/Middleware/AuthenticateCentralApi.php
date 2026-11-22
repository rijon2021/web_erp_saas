<?php

namespace App\Http\Middleware;

use App\Models\Central\CentralApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Super-admin mobile API (/api/super/*): resolves the Bearer token to a
 * central admin and puts it on the 'central' guard, so code shared with the
 * web panel (Auth::guard('central')->user()) sees the same admin.
 */
class AuthenticateCentralApi
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        $token = $plain ? CentralApiToken::findByPlain($plain) : null;

        if (! $token || ! $token->user || $token->isExpired()) {
            return response()->json([
                'status'  => 'Unauthenticated',
                'message' => 'Your session has ended. Please sign in again.',
            ], 401);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        Auth::guard('central')->setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('central_api_token', $token);

        return $next($request);
    }
}
