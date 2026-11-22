<?php

namespace App\Services\Couriers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Courier updates also arrive with nobody logged in (webhooks, the scheduled
 * status sync). The payments, expenses and sale returns they create still
 * need a user: the person who booked the shipment when they are still
 * active, otherwise the business owner (role 1).
 */
final class ActingUser
{
    public static function resolve(?int $preferredId = null): ?User
    {
        if ($preferredId) {
            $user = User::query()->where('id', $preferredId)->where('statut', 1)->first();
            if ($user) {
                return $user;
            }
        }

        return User::query()
            ->where('statut', 1)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', 1))
            ->orderBy('id')
            ->first();
    }

    /**
     * Run $fn with $user as the authenticated API user (Auth::user() and
     * $request->user('api') inside existing controllers), then restore.
     */
    public static function run(User $user, callable $fn)
    {
        $previousDefault = Auth::getDefaultDriver();
        $guard = Auth::guard('api');
        $previousUser = $guard->hasUser() ? $guard->user() : null;

        Auth::shouldUse('api');
        $guard->setUser($user);

        try {
            return $fn();
        } finally {
            if ($previousUser) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
            Auth::shouldUse($previousDefault);
        }
    }
}
