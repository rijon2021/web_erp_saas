<?php

namespace App\Support\Security;

use App\Mail\TwoFactorOtpMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Two-factor authentication for client portal logins (PortalClient) — ported
 * from stocky, where the same class also serves staff and store customers;
 * only the customer half is kept here. portal_clients carries
 * two_factor_method / _secret / _recovery_codes / _confirmed_at.
 *
 * Methods: 'app' — an authenticator app (TOTP) plus recovery codes; 'email' —
 * a 6-digit code mailed at sign-in.
 *
 * An account has 2FA once two_factor_confirmed_at is set — only after a valid
 * code proved the method works, so a half-finished setup never locks anyone
 * out. The secret is encrypted with APP_KEY; recovery codes are stored as
 * sha256 hashes (shown once, single use). Codes, blocks and replay guards
 * live in the cache, which tenancy prefixes per tenant.
 *
 * Guessing is capped per account, whatever the lockout settings: MAX_FAILURES
 * wrong codes block code entry for LOCK_MINUTES.
 */
final class TwoFactor
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const RECOVERY_CODE_COUNT = 8;

    /** Emailed codes: valid this long, and one new mail per account per RESEND_SECONDS. */
    public const EMAIL_CODE_MINUTES = 10;

    public const RESEND_SECONDS = 60;

    /** Customers' "trust this device" length (staff use the admin's setting). */
    public const CUSTOMER_TRUST_DAYS = 30;

    private const TRUST_COOKIE = 'stocky_2fa_trust_';

    public static function migrated(string $table = 'portal_clients'): bool
    {
        static $migrated = [];

        try {
            // Keyed by database: a queue worker serves many tenants, and one
            // may be migrated while another is not.
            $key = \DB::connection()->getDatabaseName().'.'.$table;

            return $migrated[$key] ??= \Schema::hasColumn($table, 'two_factor_confirmed_at');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function method(?Model $account): string
    {
        if (! $account || $account instanceof User) {
            return 'app';
        }

        return $account->two_factor_method === 'email' ? 'email' : 'app';
    }

    public static function enabled(?Model $account): bool
    {
        if ($account === null || empty($account->two_factor_confirmed_at)) {
            return false;
        }

        return self::method($account) === 'email' || ! empty($account->two_factor_secret);
    }

    // ------------------------------------------------------------ enrolment

    public static function issuer(): string
    {
        $row = DB::table('settings')->whereNull('deleted_at')->first(['CompanyName', 'app_name']);
        $name = $row ? ($row->CompanyName ?: $row->app_name) : null;

        return trim((string) ($name ?: config('app.name', 'Stocky'))) ?: 'Stocky';
    }

    /** @return array{secret: string, uri: string} */
    public static function newSetup(Model $account, ?string $issuer = null): array
    {
        $secret = Totp::generateSecret();

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, (string) $account->email, $issuer ?: self::issuer()),
        ];
    }

    /**
     * Turn on authenticator-app 2FA with a secret the account just proved
     * with a code.
     *
     * @return string[] the plain recovery codes, to show once
     */
    public static function enable(Model $account, string $secret): array
    {
        $codes = self::makeRecoveryCodes();

        $account->forceFill(self::withMethod($account, 'app', [
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => self::encodeCodes($codes),
            'two_factor_confirmed_at' => now(),
        ]))->save();

        return $codes;
    }

    /** Turn on emailed codes (customers), after a mailed code was confirmed. */
    public static function enableEmail(Model $account): void
    {
        $account->forceFill(self::withMethod($account, 'email', [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
        ]))->save();
    }

    public static function disable(Model $account): void
    {
        $account->forceFill(self::withMethod($account, null, [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]))->save();

        Cache::forget(self::key('step', $account));
        Cache::forget(self::key('email', $account));
        self::clearFailures($account);
    }

    /** @return string[] */
    public static function regenerateRecoveryCodes(Model $account): array
    {
        $codes = self::makeRecoveryCodes();
        $account->forceFill(['two_factor_recovery_codes' => self::encodeCodes($codes)])->save();

        return $codes;
    }

    public static function recoveryCodesLeft(Model $account): int
    {
        return count(self::storedCodeHashes($account));
    }

    // ------------------------------------------------------------ emailed codes

    /**
     * Mail a fresh 6-digit code to the account's email (sign-in, or setting up
     * the email method). Returns 0 when sent, the seconds to wait when a code
     * was sent too recently, or -1 when the mail could not be sent.
     */
    public static function sendEmailCode(Model $account, ?string $brand = null): int
    {
        // The cooldown only guards a code that is still usable: once it was
        // used (or wiped by a lockout) a new sign-in must get a new code.
        $sentKey = self::key('email_sent', $account);
        $wait = (int) Cache::get($sentKey, 0) - time();
        if ($wait > 0 && Cache::has(self::key('email', $account))) {
            return $wait;
        }

        $code = str_pad((string) random_int(0, 999999), Totp::DIGITS, '0', STR_PAD_LEFT);

        try {
            (new \App\Http\Controllers\BaseController)->Set_config_mail();
            Mail::to((string) $account->email)->send(new TwoFactorOtpMail($code, $brand ?: self::issuer(), self::EMAIL_CODE_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('Two-factor code email failed: '.$e->getMessage());

            return -1;
        }

        Cache::put(self::key('email', $account), hash('sha256', $code.'|'.self::accountKey($account)), self::EMAIL_CODE_MINUTES * 60);
        Cache::put($sentKey, time() + self::RESEND_SECONDS, self::RESEND_SECONDS);

        return 0;
    }

    /** Check (and use up) the last mailed code, for confirming the email method. */
    public static function checkEmailSetupCode(Model $account, string $code): bool
    {
        return self::useEmailCode($account, $code) !== null;
    }

    // ------------------------------------------------------------ verification

    /** True when the code matches this secret (setup confirmation). */
    public static function checkSetupCode(string $secret, string $code): bool
    {
        return Totp::verify($secret, $code) !== null;
    }

    /**
     * Check a sign-in code for the account's method: an app code or a
     * recovery code (then used up), or the mailed code. Counts failures
     * toward the per-account block.
     *
     * @return 'totp'|'recovery'|'email'|null
     */
    public static function verify(Model $account, string $code): ?string
    {
        if (! self::enabled($account) || self::lockedFor($account) > 0) {
            return null;
        }

        $result = self::method($account) === 'email'
            ? self::useEmailCode($account, $code)
            : (self::verifyTotp($account, $code) ?? self::useRecoveryCode($account, $code));

        if ($result === null) {
            self::recordFailure($account);
        } else {
            self::clearFailures($account);
        }

        return $result;
    }

    /** Seconds left on this account's code-entry block, 0 when free. */
    public static function lockedFor(Model $account): int
    {
        return max(0, (int) Cache::get(self::key('lock', $account), 0) - time());
    }

    public static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return trans_choice('{1} Too many invalid codes. Try again in :count minute.|[2,*] Too many invalid codes. Try again in :count minutes.', $minutes, ['count' => $minutes]);
    }

    // ------------------------------------------------------------ trusted devices

    /** Cookie that skips the code on this browser for $days days. */
    public static function trustCookie(Model $account, int $days): Cookie
    {
        $expires = time() + $days * 86400;

        return cookie(
            self::trustCookieName($account),
            $expires.'|'.self::trustSignature($account, $expires),
            $days * 1440,
            null, null, null, true, false, 'lax'
        );
    }

    public static function isTrusted(Request $request, Model $account, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }

        $value = (string) $request->cookie(self::trustCookieName($account), '');
        [$expires, $signature] = array_pad(explode('|', $value, 2), 2, '');

        return ctype_digit($expires)
            && (int) $expires > time()
            // Never longer than the current setting.
            && (int) $expires <= time() + $days * 86400
            && hash_equals(self::trustSignature($account, (int) $expires), $signature);
    }

    // ------------------------------------------------------------ internals

    private static function verifyTotp(Model $account, string $code): ?string
    {
        $secret = self::secret($account);
        $step = $secret !== '' ? Totp::verify($secret, $code) : null;
        if ($step === null) {
            return null;
        }

        // Each code works once: a step at or before the last one used is a
        // replay. Cache::add() is atomic, so two simultaneous requests with
        // the same code can't both get through.
        $stepKey = self::key('step', $account);
        if ($step <= (int) Cache::get($stepKey, 0)
            || ! Cache::add('two_factor:used:'.self::accountKey($account).':'.$step, 1, 300)) {
            return null;
        }
        Cache::put($stepKey, $step, 300);

        return 'totp';
    }

    private static function useEmailCode(Model $account, string $code): ?string
    {
        $code = preg_replace('/\D/', '', $code);
        $key = self::key('email', $account);
        $expected = (string) Cache::get($key, '');

        if (strlen($code) !== Totp::DIGITS || $expected === ''
            || ! hash_equals($expected, hash('sha256', $code.'|'.self::accountKey($account)))) {
            return null;
        }

        // pull() semantics: a code works once, even when sent twice at once.
        if (! Cache::add($key.':used:'.$expected, 1, self::EMAIL_CODE_MINUTES * 60)) {
            return null;
        }
        Cache::forget($key);

        return 'email';
    }

    private static function useRecoveryCode(Model $account, string $code): ?string
    {
        $normalized = self::normalizeCode($code);
        if (strlen($normalized) !== 10) {
            return null;
        }

        $hash = hash('sha256', $normalized);

        // Row lock: the same code sent twice at once is used only once.
        $used = DB::transaction(function () use ($account, $hash) {
            $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->first();
            $hashes = $locked ? self::storedCodeHashes($locked) : [];
            $index = array_search($hash, $hashes, true);
            if ($index === false) {
                return false;
            }

            unset($hashes[$index]);
            $locked->forceFill([
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes))),
            ])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        return $used ? 'recovery' : null;
    }

    private static function recordFailure(Model $account): void
    {
        $failKey = self::key('fail', $account);
        $failures = (int) Cache::get($failKey, 0) + 1;

        if ($failures >= self::MAX_FAILURES) {
            Cache::put(self::key('lock', $account), time() + self::LOCK_MINUTES * 60, self::LOCK_MINUTES * 60);
            Cache::forget($failKey);
            // A mailed code must not outlive the block.
            Cache::forget(self::key('email', $account));

            return;
        }

        Cache::put($failKey, $failures, self::LOCK_MINUTES * 60);
    }

    private static function clearFailures(Model $account): void
    {
        Cache::forget(self::key('fail', $account));
        Cache::forget(self::key('lock', $account));
    }

    private static function secret(Model $account): string
    {
        try {
            return Crypt::decryptString((string) $account->two_factor_secret);
        } catch (\Throwable $e) {
            // APP_KEY rotated: the account needs a reset.
            return '';
        }
    }

    /** @return string[] */
    private static function storedCodeHashes(Model $account): array
    {
        if (empty($account->two_factor_recovery_codes)) {
            return [];
        }

        try {
            $hashes = json_decode(Crypt::decryptString($account->two_factor_recovery_codes), true);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    /** @return string[] like "k7m2p-x9w4q" */
    private static function makeRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return array_values($codes);
    }

    /** @param string[] $codes */
    private static function encodeCodes(array $codes): string
    {
        return Crypt::encryptString(json_encode(array_map(
            fn ($c) => hash('sha256', self::normalizeCode($c)),
            $codes
        )));
    }

    private static function normalizeCode(string $code): string
    {
        return Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** The users table has no two_factor_method column. */
    private static function withMethod(Model $account, ?string $method, array $attributes): array
    {
        if (! $account instanceof User) {
            $attributes['two_factor_method'] = $method;
        }

        return $attributes;
    }

    private static function trustSignature(Model $account, int $expires): string
    {
        return hash_hmac(
            'sha256',
            self::accountKey($account).'|'.$expires.'|'.self::method($account).'|'.$account->two_factor_secret.'|'.$account->two_factor_confirmed_at,
            (string) config('app.key')
        );
    }

    private static function trustCookieName(Model $account): string
    {
        return self::TRUST_COOKIE.str_replace([':', '-'], '_', self::accountKey($account));
    }

    /** Staff keep the plain id (unchanged keys and cookies); customers are prefixed by type. */
    private static function accountKey(Model $account): string
    {
        $id = (string) (int) $account->getKey();

        return $account instanceof User ? $id : Str::snake(class_basename($account)).'-'.$id;
    }

    private static function key(string $kind, Model $account): string
    {
        return 'two_factor:'.$kind.':'.self::accountKey($account);
    }
}
