<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * JSON twin of CheckCentralPermission for the super-admin mobile API: the
 * same permission keys as the web panel, answered with 403 instead of a
 * redirect. Runs after AuthenticateCentralApi.
 */
class CentralApiPermission
{
    public function handle(Request $request, Closure $next, string ...$sections)
    {
        $user = Auth::guard('central')->user();

        if ($user && $user->hasAnyPermission($sections)) {
            return $next($request);
        }

        return response()->json([
            'status'  => 'Forbidden',
            'message' => 'Your account does not have access to this section.',
        ], 403);
    }
}
