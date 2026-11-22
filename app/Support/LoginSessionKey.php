<?php

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;

/**
 * Identity of one browser session in user_login_sessions.
 *
 * The admin runs on Passport's cookie guard (SafeCreateFreshApiToken ->
 * CreateFreshApiToken), so a login creates NO oauth_access_tokens row: the
 * laravel_token JWT carries the web session's CSRF token instead. That CSRF
 * token is therefore the only identifier both halves of the app can compute --
 * the web guard from the session, the api guard by decoding the JWT cookie --
 * so sessions are keyed as "cookie:<sha256(csrf)>".
 *
 * Web-side writers also store the raw session id in user_login_sessions.session_id
 * so a revoked session can be kicked out of the Blade shell (LogoutRevokedWebSession).
 */
class LoginSessionKey
{
    public static function fromCsrfToken(string $csrf): string
    {
        return 'cookie:'.hash('sha256', $csrf);
    }

    /**
     * Key of the current web session. Requires StartSession (the "web" group).
     */
    public static function fromSession(Request $request): ?string
    {
        try {
            $csrf = (string) $request->session()->token();
        } catch (\Throwable $e) {
            return null;
        }

        return $csrf !== '' ? self::fromCsrfToken($csrf) : null;
    }

    /**
     * Key of the session behind the current API request, read from the Passport
     * cookie. Pass $expectedUserId to reject a cookie belonging to someone else.
     */
    public static function fromPassportCookie(Request $request, ?int $expectedUserId = null): ?string
    {
        $decoded = self::decodePassportCookie($request);
        if ($decoded === null) {
            return null;
        }

        $sub = isset($decoded['sub']) ? (int) $decoded['sub'] : null;
        $csrf = isset($decoded['csrf']) ? (string) $decoded['csrf'] : null;
        $expiry = isset($decoded['expiry']) ? (int) $decoded['expiry'] : null;

        if (! $csrf || ! $expiry) {
            return null;
        }
        if ($expectedUserId !== null && $sub !== $expectedUserId) {
            return null;
        }

        return self::fromCsrfToken($csrf);
    }

    /**
     * Unix time the Passport cookie behind this API request was issued. The JWT
     * only carries its expiry, set to now + session.lifetime when
     * CreateFreshApiToken mints it (on every signed-in web GET).
     */
    public static function passportCookieIssuedAt(Request $request): ?int
    {
        $decoded = self::decodePassportCookie($request);
        if ($decoded === null || empty($decoded['expiry'])) {
            return null;
        }

        return (int) $decoded['expiry'] - (int) config('session.lifetime', 120) * 60;
    }

    private static function decodePassportCookie(Request $request): ?array
    {
        $jwtCookie = $request->cookie(Passport::cookie());
        if (! $jwtCookie) {
            return null;
        }

        try {
            /** @var Encrypter $encrypter */
            $encrypter = app(Encrypter::class);
            $raw = Passport::$decryptsCookies
                ? CookieValuePrefix::remove($encrypter->decrypt($jwtCookie, Passport::$unserializesCookies))
                : $jwtCookie;

            return (array) JWT::decode(
                $raw,
                new Key(Passport::tokenEncryptionKey($encrypter), 'HS256')
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
