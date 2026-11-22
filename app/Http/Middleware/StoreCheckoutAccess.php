<?php

namespace App\Http\Middleware;

use App\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Checkout + thank-you pages: open to guests while the admin has guest
 * checkout switched on; otherwise behaves exactly like auth:store
 * (sign-in redirect, or 401 for JSON).
 */
class StoreCheckoutAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::guard('store')->check() && StoreSetting::guestCheckoutEnabled()) {
            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next, 'store');
    }
}
