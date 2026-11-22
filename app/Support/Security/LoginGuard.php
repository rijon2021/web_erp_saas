<?php

namespace App\Support\Security;

use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Brute-force protection shared by the tenant staff login (and its API token
 * twin, password-reset form) and the super-admin login.
 *
 * Lockout: failures are counted per key (email+IP, email, or IP — the admin's
 * choice) and forgotten after an hour without failures or on a successful
 * sign-in. Reaching the limit blocks that key for exactly lockout_minutes from
 * that moment; while blocked, even the right password is refused.
 *
 * CAPTCHA "after failures" mode counts failures per IP independently, so the
 * challenge appears for whoever is guessing, whatever the email.
 *
 * State lives in the cache, which is tenant-prefixed under tenancy, so one
 * tenant's lockouts never touch another's.
 */
final class LoginGuard
{
    private const FAILURE_MEMORY_SECONDS = 3600;

    private function __construct(
        private array $options,
        private string $context,
    ) {
    }

    public static function tenant(): self
    {
        return new self(SecurityOptions::get(SecurityOptions::TENANT), SecurityOptions::TENANT);
    }

    public static function central(): self
    {
        return new self(SecurityOptions::get(SecurityOptions::CENTRAL), SecurityOptions::CENTRAL);
    }

    public function options(): array
    {
        return $this->options;
    }

    /**
     * "Remember me" is off while inactivity auto-logout is on: a remember
     * cookie would sign an idle user straight back in once the web session
     * expired, defeating the timeout.
     */
    public function rememberAllowed(): bool
    {
        return $this->options['session_timeout_minutes'] <= 0;
    }

    // ------------------------------------------------------------- captcha

    public function captcha(): Captcha
    {
        return $this->context === SecurityOptions::CENTRAL
            ? new Captcha($this->options, 'central_login_captcha', route('central.login.captcha'))
            : new Captcha($this->options, 'login_captcha', url('login/captcha'));
    }

    /** Whether the login form must show (and the POST must pass) a CAPTCHA. */
    public function captchaRequired(Request $request): bool
    {
        if (! $this->options['captcha_enabled']) {
            return false;
        }
        if ($this->options['captcha_mode'] === 'always') {
            return true;
        }

        return (int) Cache::get($this->key('captcha', (string) $request->ip()), 0) >= $this->options['captcha_after_failures'];
    }

    public function captchaOnForgotPassword(): bool
    {
        return $this->options['captcha_enabled'] && $this->options['captcha_on_forgot_password'];
    }

    /** @throws ValidationException */
    public function verifyCaptcha(Request $request, ?string $email = null): void
    {
        if ($this->captcha()->verify($request)) {
            return;
        }

        $this->log($request, $email, 'captcha_failed');

        throw ValidationException::withMessages([
            Captcha::FIELD => __('The security check failed. Please try again.'),
        ]);
    }

    // ------------------------------------------------------------- lockout

    /** Seconds left on an active lockout for this email/IP, 0 when free. */
    public function lockedFor(Request $request, string $email): int
    {
        if (! $this->options['lockout_enabled']) {
            return 0;
        }

        $until = (int) Cache::get($this->lockKey($request, $email), 0);

        return max(0, $until - time());
    }

    /** @throws ValidationException */
    public function ensureNotLocked(Request $request, string $email, string $field = 'email'): void
    {
        $seconds = $this->lockedFor($request, $email);
        if ($seconds <= 0) {
            return;
        }

        $this->log($request, $email, 'blocked');

        throw ValidationException::withMessages([$field => $this->lockedMessage($seconds)]);
    }

    /**
     * Count a wrong password. Returns the message to show: the lockout notice
     * when this failure triggered it, else the generic error (optionally with
     * the attempts left).
     */
    public function recordFailure(Request $request, string $email): string
    {
        $this->bump($this->key('captcha', (string) $request->ip()));
        $generic = __('These credentials do not match our records.');

        if (! $this->options['lockout_enabled']) {
            $this->log($request, $email, 'failed');

            return $generic;
        }

        $failKey = $this->lockKey($request, $email, 'fail');
        $failures = $this->bump($failKey);
        $max = $this->options['lockout_max_attempts'];

        if ($failures >= $max) {
            $seconds = $this->options['lockout_minutes'] * 60;
            Cache::put($this->lockKey($request, $email), time() + $seconds, $seconds);
            Cache::forget($failKey);
            $this->indexLock($email, (string) $request->ip(), time() + $seconds);
            $this->log($request, $email, 'locked_out');

            return $this->lockedMessage($seconds);
        }

        $this->log($request, $email, 'failed');

        if ($this->options['lockout_show_remaining']) {
            $left = $max - $failures;

            return $generic.' '.trans_choice('{1} :count attempt left before sign-in is blocked.|[2,*] :count attempts left before sign-in is blocked.', $left, ['count' => $left]);
        }

        return $generic;
    }

    public function recordSuccess(Request $request, string $email, ?int $userId = null): void
    {
        Cache::forget($this->key('captcha', (string) $request->ip()));
        Cache::forget($this->lockKey($request, $email, 'fail'));
        $this->log($request, $email, 'success', $userId);
    }

    /**
     * Lift every lockout/failure counter for this email and/or IP, whatever
     * scope was active when it was set (the admin may have changed it since).
     * Must run in the context (tenant or central) that set the lockout.
     */
    public static function unlock(string $context, ?string $email, ?string $ip): void
    {
        $guard = new self(SecurityOptions::DEFAULTS, $context);
        $email = strtolower(trim((string) $email));
        $ip = (string) $ip;

        $ids = [];
        if ($email !== '') {
            $ids[] = 'email:'.$email;
        }
        if ($ip !== '') {
            $ids[] = 'ip:'.$ip;
            Cache::forget($guard->key('captcha', $ip));
        }
        if ($email !== '' && $ip !== '') {
            $ids[] = 'email_ip:'.$email.'|'.$ip;
        }

        foreach ($ids as $id) {
            Cache::forget($guard->key('lock', $id));
            Cache::forget($guard->key('fail', $id));
        }
    }

    /**
     * Lockouts in force right now, for the Security tab's "Blocked right now"
     * list. Kept in the cache next to the locks themselves, so it works
     * whether or not sign-in activity is being recorded.
     *
     * @return array<int, array{email: string, ip_address: string, locked_at: int, seconds_left: int}>
     */
    public static function activeLocks(string $context): array
    {
        $guard = new self(SecurityOptions::DEFAULTS, $context);
        $out = [];

        foreach ((array) Cache::get($guard->key('index', 'locks'), []) as $row) {
            // lockRemaining() reflects unlocks and expiry, whatever the scope.
            $left = self::lockRemaining($context, $row['email'] ?? '', $row['ip_address'] ?? '');
            if ($left > 0) {
                $out[] = $row + ['seconds_left' => $left];
            }
        }

        usort($out, fn ($a, $b) => $b['locked_at'] <=> $a['locked_at']);

        return $out;
    }

    private function indexLock(string $email, string $ip, int $until): void
    {
        $key = $this->key('index', 'locks');
        $now = time();

        $rows = array_filter((array) Cache::get($key, []), fn ($r) => ($r['until'] ?? 0) > $now);
        $rows[sha1(strtolower(trim($email)).'|'.$ip)] = [
            'email' => strtolower(trim($email)),
            'ip_address' => $ip,
            'locked_at' => $now,
            'until' => $until,
        ];

        // Longest possible lockout is 24h (lockout_minutes max 1440).
        Cache::put($key, array_slice($rows, -500, null, true), 86400);
    }

    /** Seconds left on any lockout touching this email/IP pair, any scope. */
    public static function lockRemaining(string $context, ?string $email, ?string $ip): int
    {
        $guard = new self(SecurityOptions::DEFAULTS, $context);
        $email = strtolower(trim((string) $email));
        $ids = array_filter([
            $email !== '' ? 'email:'.$email : null,
            $ip ? 'ip:'.$ip : null,
            ($email !== '' && $ip) ? 'email_ip:'.$email.'|'.$ip : null,
        ]);

        $until = 0;
        foreach ($ids as $id) {
            $until = max($until, (int) Cache::get($guard->key('lock', $id), 0));
        }

        return max(0, $until - time());
    }

    // ------------------------------------------------------------- helpers

    private function lockedMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $seconds < 60
            ? trans_choice('{1} Too many failed sign-in attempts. Try again in :count second.|[2,*] Too many failed sign-in attempts. Try again in :count seconds.', $seconds, ['count' => $seconds])
            : trans_choice('{1} Too many failed sign-in attempts. Try again in :count minute.|[2,*] Too many failed sign-in attempts. Try again in :count minutes.', $minutes, ['count' => $minutes]);
    }

    private function lockKey(Request $request, string $email, string $kind = 'lock'): string
    {
        $email = strtolower(trim($email));
        $ip = (string) $request->ip();

        $id = match ($this->options['lockout_scope']) {
            'email' => 'email:'.$email,
            'ip' => 'ip:'.$ip,
            default => 'email_ip:'.$email.'|'.$ip,
        };

        return $this->key($kind, $id);
    }

    private function key(string $kind, string $id): string
    {
        return 'login_guard:'.$this->context.':'.$kind.':'.sha1($id);
    }

    private function bump(string $key): int
    {
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, self::FAILURE_MEMORY_SECONDS);

        return $count;
    }

    private function log(Request $request, ?string $email, string $event, ?int $userId = null): void
    {
        if ($this->context !== SecurityOptions::TENANT || ! $this->options['log_login_attempts']) {
            return;
        }

        try {
            LoginAttempt::query()->insert([
                'email' => $email !== null ? mb_substr(strtolower(trim($email)), 0, 191) : null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'event' => $event,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            // Keep the log bounded without a scheduler entry.
            if (random_int(1, 100) === 1) {
                LoginAttempt::query()->where('created_at', '<', now()->subDays(LoginAttempt::RETENTION_DAYS))->delete();
            }
        } catch (\Throwable $e) {
            // Table not migrated yet: logging is best-effort, never block a login.
        }
    }
}
