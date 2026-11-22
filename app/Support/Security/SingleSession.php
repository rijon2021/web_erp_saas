<?php

namespace App\Support\Security;

use App\Models\OauthAccessToken;
use App\Models\OauthRefreshToken;
use App\Models\UserLoginSession;
use Illuminate\Support\Str;

/**
 * System Settings > Security "one active session per user", shared by every
 * staff sign-in path (web login, mobile app login, API token endpoint).
 */
final class SingleSession
{
    /**
     * Revoke every session and API token of the user except this device's.
     * SPA cookie sessions are blocked by EnforceApiTokenTimeout (409, the SPA
     * then logs out) on their next request; API tokens die now.
     *
     * @param  string[]  $keepKeys  user_login_sessions.access_token_id values of this device
     */
    public static function revokeOthers(int $userId, array $keepKeys = [], ?string $keepSessionId = null): void
    {
        UserLoginSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->when($keepSessionId !== null, fn ($q) => $q->where(fn ($qq) => $qq->whereNull('session_id')->orWhere('session_id', '!=', $keepSessionId)))
            ->when($keepKeys !== [], fn ($q) => $q->where(fn ($qq) => $qq->whereNull('access_token_id')->orWhereNotIn('access_token_id', $keepKeys)))
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        $tokenIds = OauthAccessToken::query()
            ->where('user_id', $userId)
            ->where('revoked', '=', 0)
            ->when($keepKeys !== [], fn ($q) => $q->whereNotIn('id', $keepKeys))
            ->pluck('id')
            ->all();

        if ($tokenIds) {
            OauthAccessToken::query()->whereIn('id', $tokenIds)->update(['revoked' => 1, 'updated_at' => now()]);
            OauthRefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => 1]);
        }
    }

    /**
     * A signed-out device whose browser was closed would otherwise come back
     * through its "remember me" cookie: a new token voids every such cookie.
     */
    public static function rotateRememberToken($user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
