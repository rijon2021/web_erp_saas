<?php

namespace App\Support\Security;

use App\Models\OauthAccessToken;
use App\Models\OauthRefreshToken;
use App\Models\User;
use App\Models\UserLoginSession;

/**
 * Cut every live credential of a staff user: Passport access + refresh tokens
 * (mobile app, API clients, POS PATs) and the device sessions shown under
 * Security → Login devices (incl. cookie-auth "cookie:*" sessions, which
 * EnforceApiTokenTimeout refuses once revoked_at is set).
 *
 * Used when a user is deactivated or deleted. Is_Active additionally refuses
 * any request from a disabled user, so this is belt and braces.
 */
class RevokeUserAccess
{
    public static function all(int $userId): void
    {
        $tokenIds = OauthAccessToken::query()
            ->where('user_id', $userId)
            ->where('revoked', '=', 0)
            ->pluck('id')
            ->all();

        if ($tokenIds) {
            OauthAccessToken::query()->whereIn('id', $tokenIds)->update([
                'revoked' => 1,
                'updated_at' => now(),
            ]);
            OauthRefreshToken::query()->whereIn('access_token_id', $tokenIds)->update([
                'revoked' => 1,
            ]);
        }

        UserLoginSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** True when the account may not use the app (deactivated or deleted). */
    public static function isDisabled($user): bool
    {
        // Only staff users carry statut; other authenticatables (portal/store
        // customers) are never judged here.
        return $user instanceof User && (! $user->statut || $user->deleted_at !== null);
    }
}
