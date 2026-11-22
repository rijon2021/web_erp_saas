<?php

namespace App\Support\Security;

use App\Models\Central\GeneralSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Login security options, stored as JSON:
 *  - tenant:  settings.security_options          (System Settings > Security)
 *  - central: general_settings.security_options  (super admin Settings > Security)
 *
 * Every read goes through sanitize(), so callers always get the full key set
 * with valid values. The CAPTCHA secret key is encrypted at rest and never
 * sent to the browser (forClient() swaps it for a "set" flag).
 */
final class SecurityOptions
{
    public const TENANT = 'tenant';

    public const CENTRAL = 'central';

    public const PROVIDERS = ['builtin', 'recaptcha', 'turnstile', 'hcaptcha'];

    public const CAPTCHA_MODES = ['always', 'after_failures'];

    public const LOCKOUT_SCOPES = ['email_ip', 'email', 'ip'];

    public const DEFAULTS = [
        'captcha_enabled' => false,
        'captcha_provider' => 'builtin',
        'captcha_site_key' => '',
        'captcha_secret_key' => '',
        'captcha_mode' => 'always',
        'captcha_after_failures' => 3,
        'captcha_on_forgot_password' => false,

        'lockout_enabled' => false,
        'lockout_max_attempts' => 5,
        'lockout_minutes' => 10,
        'lockout_scope' => 'email_ip',
        'lockout_show_remaining' => false,

        'single_session' => false,
        'session_timeout_minutes' => 0,
        'log_login_attempts' => false,
    ];

    /** Keys the super-admin page manages; the rest only apply to tenants. */
    public const CENTRAL_KEYS = [
        'captcha_enabled', 'captcha_provider', 'captcha_site_key', 'captcha_secret_key',
        'captcha_mode', 'captcha_after_failures',
        'lockout_enabled', 'lockout_max_attempts', 'lockout_minutes', 'lockout_scope',
        'lockout_show_remaining',
    ];

    private const CACHE_SECONDS = 60;

    public static function get(string $context): array
    {
        return Cache::remember(self::cacheKey($context), self::CACHE_SECONDS, function () use ($context) {
            return self::sanitize(self::readRaw($context));
        });
    }

    /**
     * Merge a request payload over the stored options and persist them.
     * An empty secret keeps the stored one (the form never receives it).
     */
    public static function save(string $context, array $input): array
    {
        $stored = self::sanitize(self::readRaw($context));

        if ($context === self::CENTRAL) {
            $input = array_intersect_key($input, array_flip(self::CENTRAL_KEYS));
        }

        $secret = trim((string) ($input['captcha_secret_key'] ?? ''));
        $input['captcha_secret_key'] = $secret === ''
            ? $stored['captcha_secret_key']
            : Crypt::encryptString($secret);

        $options = self::sanitize(array_merge($stored, $input));
        $json = json_encode($options);

        if ($context === self::CENTRAL) {
            GeneralSetting::instance()->forceFill(['security_options' => $json])->save();
        } else {
            DB::table('settings')->whereNull('deleted_at')->update(['security_options' => $json]);
        }

        self::forget($context);

        return $options;
    }

    /**
     * Turning on an external CAPTCHA with wrong keys would lock everyone out,
     * the admin included. So whenever the effective provider/keys change, the
     * settings page must send a token from a live widget built with the new
     * site key, and it is verified here with the new secret before saving.
     * Returns an error message, or null when the save may proceed.
     */
    public static function captchaKeyProblem(string $context, array $input, ?string $testToken, ?string $ip): ?string
    {
        $stored = self::get($context);
        $enabled = filter_var($input['captcha_enabled'] ?? $stored['captcha_enabled'], FILTER_VALIDATE_BOOLEAN);
        $provider = $input['captcha_provider'] ?? $stored['captcha_provider'];

        if (! $enabled || $provider === 'builtin') {
            return null;
        }

        $siteKey = trim((string) (array_key_exists('captcha_site_key', $input) ? $input['captcha_site_key'] : $stored['captcha_site_key']));
        $newSecret = trim((string) ($input['captcha_secret_key'] ?? ''));
        $secret = $newSecret !== '' ? $newSecret : self::secret($stored);

        if ($siteKey === '' || $secret === '') {
            return __('Enter both the site key and the secret key for the selected CAPTCHA provider.');
        }

        $unchanged = $stored['captcha_enabled']
            && $stored['captcha_provider'] === $provider
            && $stored['captcha_site_key'] === $siteKey
            && $newSecret === '';
        if ($unchanged) {
            return null;
        }

        if (! Captcha::verifyToken($provider, $secret, (string) $testToken, $ip)) {
            return __('Complete the CAPTCHA test with the new keys before saving. If the widget does not load, the site key is wrong or this domain is not allowed for it; if the check fails, the secret key is wrong.');
        }

        return null;
    }

    public static function forget(string $context): void
    {
        Cache::forget(self::cacheKey($context));
    }

    public static function secret(array $options): string
    {
        $value = (string) ($options['captcha_secret_key'] ?? '');
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // APP_KEY rotated: treat as unset so the admin re-enters it.
            return '';
        }
    }

    /** Safe to serialize to the settings page. */
    public static function forClient(array $options, string $context): array
    {
        $out = $options;
        $out['captcha_secret_set'] = self::secret($options) !== '';
        $out['captcha_secret_key'] = '';

        if ($context === self::CENTRAL) {
            $out = array_intersect_key($out, array_flip([...self::CENTRAL_KEYS, 'captcha_secret_set']));
        }

        return $out;
    }

    public static function sanitize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $o = self::DEFAULTS;

        foreach (['captcha_enabled', 'captcha_on_forgot_password', 'lockout_enabled', 'lockout_show_remaining', 'single_session', 'log_login_attempts'] as $key) {
            if (array_key_exists($key, $raw)) {
                $o[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $o['captcha_provider'] = self::oneOf($raw['captcha_provider'] ?? null, self::PROVIDERS, $o['captcha_provider']);
        $o['captcha_mode'] = self::oneOf($raw['captcha_mode'] ?? null, self::CAPTCHA_MODES, $o['captcha_mode']);
        $o['lockout_scope'] = self::oneOf($raw['lockout_scope'] ?? null, self::LOCKOUT_SCOPES, $o['lockout_scope']);

        $o['captcha_site_key'] = mb_substr(trim((string) ($raw['captcha_site_key'] ?? '')), 0, 255);
        $o['captcha_secret_key'] = (string) ($raw['captcha_secret_key'] ?? '');

        $o['captcha_after_failures'] = self::int($raw['captcha_after_failures'] ?? null, 1, 20, $o['captcha_after_failures']);
        $o['lockout_max_attempts'] = self::int($raw['lockout_max_attempts'] ?? null, 2, 50, $o['lockout_max_attempts']);
        $o['lockout_minutes'] = self::int($raw['lockout_minutes'] ?? null, 1, 1440, $o['lockout_minutes']);

        // 0 = never; otherwise at least 5 minutes so an admin can't make the
        // app unusable by saving a 1-minute timeout.
        $timeout = self::int($raw['session_timeout_minutes'] ?? null, 0, 43200, 0);
        $o['session_timeout_minutes'] = $timeout > 0 ? max(5, $timeout) : 0;

        return $o;
    }

    private static function readRaw(string $context): ?string
    {
        try {
            if ($context === self::CENTRAL) {
                return GeneralSetting::query()->value('security_options');
            }

            return DB::table('settings')->whereNull('deleted_at')->value('security_options');
        } catch (\Throwable $e) {
            // Column not migrated yet: everything stays off (DEFAULTS).
            return null;
        }
    }

    private static function cacheKey(string $context): string
    {
        // Tenant caches are already prefixed per tenant (PrefixCacheTenancyBootstrapper).
        return 'login_security_options:'.$context;
    }

    private static function oneOf($value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function int($value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
