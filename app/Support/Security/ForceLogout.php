<?php

namespace App\Support\Security;

use App\Models\OauthAccessToken;
use App\Models\OauthRefreshToken;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\LoginSessionKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin "force logout": signs a tenant's users (all of them, or one) out
 * of every device and browser; they must sign in again to continue.
 *
 * Revoking the sessions we know about -- Login Devices rows, Passport tokens,
 * remember-me cookies -- is not enough on its own: a browser session that never
 * got a user_login_sessions row would carry on. So each user also gets a
 * cutoff, users.sessions_revoked_at (UTC), and any session that signed in at or
 * before it is refused:
 *
 *  - web:  the sign-in time is kept in the session (stampWebSignIn, on the
 *          Login event) and checked by LogoutRevokedWebSession
 *  - api:  Authenticate checks when the SPA's Passport cookie was issued; it is
 *          re-minted on every signed-in page load, so an old one means an old
 *          session. Bearer tokens need no check: they are all revoked here.
 */
final class ForceLogout
{
    public const COLUMN = 'sessions_revoked_at';

    public const SESSION_KEY = 'auth_signed_in_at';

    /**
     * Sign the user (or, with null, every user) out everywhere. Must run in the
     * tenant's context. "users" is how many were signed out; "cutoff_applied"
     * is false until the tenant migration adding the cutoff column has run.
     *
     * @return array{users: int, cutoff_applied: bool}
     */
    public static function revoke(?int $userId = null): array
    {
        $now = now();
        $forUser = fn ($q) => $userId !== null ? $q->where('user_id', $userId) : $q;

        // Before the migration runs there is no cutoff column; everything else
        // still applies, so only sessions with no Login Devices row slip through.
        $cutoffApplied = Schema::hasColumn('users', self::COLUMN);

        $users = User::query()->when($userId !== null, fn ($q) => $q->whereKey($userId));
        $count = (clone $users)->count();
        if ($count === 0) {
            return ['users' => 0, 'cutoff_applied' => $cutoffApplied];
        }

        // A null remember token voids every "remember me" cookie, which would
        // otherwise sign a closed browser straight back in. toBase(): leave
        // updated_at alone, nothing about the user record changed.
        $values = ['remember_token' => null];
        if ($cutoffApplied) {
            $values[self::COLUMN] = $now->copy()->utc()->format('Y-m-d H:i:s');
        }
        $users->toBase()->update($values);

        // Settings > Login Devices rows: the SPA gets a 409 on its next call,
        // the Blade shell is logged out on its next page load.
        $forUser(UserLoginSession::query()->whereNull('revoked_at'))
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        // Mobile app / API tokens die now.
        $tokens = $forUser(OauthAccessToken::query()->where('revoked', 0));
        OauthRefreshToken::query()
            ->where('revoked', 0)
            ->whereIn('access_token_id', (clone $tokens)->select('id'))
            ->update(['revoked' => 1]);
        $tokens->update(['revoked' => 1, 'updated_at' => $now]);

        return ['users' => $count, 'cutoff_applied' => $cutoffApplied];
    }

    /**
     * Login event (web guard): remember when this browser session signed in.
     */
    public static function stampWebSignIn(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, time());
        }
    }

    /**
     * Did this web session sign in before the user was force-logged-out?
     * A session with no stamp predates this feature, so it counts as older.
     */
    public static function webSessionIsStale(Request $request, $user): bool
    {
        $cutoff = self::cutoff($user);
        if ($cutoff === null || ! $request->hasSession()) {
            return false;
        }

        $signedInAt = $request->session()->get(self::SESSION_KEY);

        return ! is_numeric($signedInAt) || (int) $signedInAt <= $cutoff;
    }

    /**
     * Is this API request riding on a Passport cookie minted before the user
     * was force-logged-out? An undecodable cookie is let through (the web side
     * still signs that browser out): refusing it would loop the user between
     * the login page and a 409 forever.
     */
    public static function apiCookieIsStale(Request $request, $user): bool
    {
        $cutoff = self::cutoff($user);
        if ($cutoff === null) {
            return false;
        }

        // Bearer token (mobile app, API clients): revoke() already killed every
        // token older than the cutoff.
        if (method_exists($user, 'token') && $user->token() instanceof Model) {
            return false;
        }

        $issuedAt = LoginSessionKey::passportCookieIssuedAt($request);

        return $issuedAt !== null && $issuedAt <= $cutoff;
    }

    /** The user's cutoff as a Unix timestamp. */
    public static function cutoff($user): ?int
    {
        $at = self::revokedAt($user);

        return $at?->getTimestamp();
    }

    /** When the user was last force-logged-out, for display. */
    public static function revokedAt($user): ?Carbon
    {
        $raw = $user instanceof User ? ($user->getAttributes()[self::COLUMN] ?? null) : null;
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
