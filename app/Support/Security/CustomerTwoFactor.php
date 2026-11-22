<?php

namespace App\Support\Security;

use App\Models\PortalClient;
use App\Support\PortalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The customer side of two-factor sign-in for the client portal ('portal':
 * PortalClient, JSON API). Stocky shares it with its online store ('store');
 * the scope argument is kept so future surfaces can plug in. The web session holds:
 *
 *  - "{scope}_two_factor_login": a sign-in whose password was right and
 *    still needs its code (the guard is not logged in until it passes);
 *  - "{scope}_two_factor_setup": a setup in progress on the account page
 *    (method, and the new authenticator secret).
 *
 * Optional for customers: the portal admin only decides whether
 * they may turn it on. An account that already has 2FA keeps being asked even
 * if the option is later switched off, and can still turn it off itself.
 * The codes themselves are checked by TwoFactor.
 */
final class CustomerTwoFactor
{
    public const SCOPES = ['portal' => PortalClient::class];

    /** The code step must be finished this long after the password. */
    private const PENDING_SECONDS = 600;

    public static function featureEnabled(string $scope): bool
    {
        if (! TwoFactor::migrated(self::table($scope))) {
            return false;
        }

        return (bool) (PortalSettings::all()['two_factor_enabled'] ?? false);
    }

    /** Name shown in the authenticator app and in the code email. */
    public static function brand(string $scope): string
    {
        return TwoFactor::issuer();
    }

    // ------------------------------------------------------------ sign-in step

    /**
     * After a correct password: true when the account must pass the code step
     * (it is then parked in the session and, for email, the code is mailed).
     */
    public static function startChallenge(Request $request, string $scope, Model $account, bool $remember, array $extra = []): bool
    {
        if (! TwoFactor::migrated(self::table($scope)) || ! TwoFactor::enabled($account)
            || TwoFactor::isTrusted($request, $account, TwoFactor::CUSTOMER_TRUST_DAYS)) {
            return false;
        }

        $request->session()->put(self::pendingKey($scope), [
            'id' => (int) $account->getKey(),
            'remember' => $remember,
            'at' => time(),
        ] + $extra);

        // Remember whether the mail went out, so the code page can say so
        // instead of promising a code that never arrives.
        if (TwoFactor::method($account) === 'email') {
            $sent = TwoFactor::sendEmailCode($account, self::brand($scope));
            $request->session()->put(self::pendingKey($scope).'.email_failed', $sent < 0);
        }

        return true;
    }

    /** @return array{0: Model, 1: array}|null the parked account and its session data */
    public static function pending(Request $request, string $scope): ?array
    {
        $data = $request->session()->get(self::pendingKey($scope));
        if (! is_array($data) || (int) ($data['at'] ?? 0) < time() - self::PENDING_SECONDS) {
            return null;
        }

        $class = self::SCOPES[$scope];
        $account = $class::query()->whereKey((int) $data['id'])->first();

        // Turned off, or the account was disabled / blocked, while the step was open.
        if (! $account || ! TwoFactor::enabled($account) || ! self::active($scope, $account)) {
            return null;
        }

        return [$account, $data];
    }

    public static function clearPending(Request $request, string $scope): void
    {
        $request->session()->forget(self::pendingKey($scope));
    }

    /**
     * Check the sign-in code. Null when it passed, else the message to show.
     */
    public static function verifyChallenge(Model $account, string $code): ?string
    {
        if ($seconds = TwoFactor::lockedFor($account)) {
            return TwoFactor::lockedMessage($seconds);
        }

        if (TwoFactor::verify($account, $code) !== null) {
            return null;
        }

        $seconds = TwoFactor::lockedFor($account);

        return $seconds > 0 ? TwoFactor::lockedMessage($seconds) : self::invalidMessage($account);
    }

    public static function invalidMessage(Model $account): string
    {
        return TwoFactor::method($account) === 'email'
            ? __('messages.TwoFactorEmailCodeInvalid')
            : __('messages.TwoFactorCodeInvalid');
    }

    // ------------------------------------------------------------ setup (account page)

    /**
     * Begin turning 2FA on. 'app' returns the new secret + otpauth URI to
     * show; 'email' mails a code and returns ['sent' => 0 | seconds to wait | -1].
     */
    public static function startSetup(Request $request, string $scope, Model $account, string $method): array
    {
        if ($method === 'email') {
            $request->session()->put(self::setupKey($scope), ['method' => 'email', 'at' => time()]);

            return ['method' => 'email', 'sent' => TwoFactor::sendEmailCode($account, self::brand($scope))];
        }

        $setup = TwoFactor::newSetup($account, self::brand($scope));
        $request->session()->put(self::setupKey($scope), ['method' => 'app', 'secret' => $setup['secret'], 'at' => time()]);

        return ['method' => 'app'] + $setup;
    }

    /** @return array{method: string, secret?: string, uri?: string}|null the setup in progress */
    public static function setupInProgress(Request $request, string $scope, Model $account): ?array
    {
        $data = $request->session()->get(self::setupKey($scope));
        if (! is_array($data) || (int) ($data['at'] ?? 0) < time() - 1800) {
            return null;
        }

        if (($data['method'] ?? '') === 'app') {
            $data['uri'] = Totp::uri($data['secret'], (string) $account->email, self::brand($scope));
        }

        return $data;
    }

    /**
     * Finish turning 2FA on with the code the customer typed.
     *
     * @return string[]|null recovery codes ([] for email), null when the code is wrong
     */
    public static function confirmSetup(Request $request, string $scope, Model $account, string $code): ?array
    {
        $setup = self::setupInProgress($request, $scope, $account);
        if (! $setup) {
            return null;
        }

        if ($setup['method'] === 'email') {
            if (! TwoFactor::checkEmailSetupCode($account, $code)) {
                return null;
            }
            TwoFactor::enableEmail($account);
            $codes = [];
        } else {
            if (! TwoFactor::checkSetupCode($setup['secret'], $code)) {
                return null;
            }
            $codes = TwoFactor::enable($account, $setup['secret']);
        }

        self::cancelSetup($request, $scope);

        return $codes;
    }

    public static function cancelSetup(Request $request, string $scope): void
    {
        $request->session()->forget(self::setupKey($scope));
    }

    // ------------------------------------------------------------ helpers

    /** "jo****@example.com" — tells the customer where the code went. */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

        return $visible.str_repeat('*', max(1, mb_strlen($name) - mb_strlen($visible))).($domain !== '' ? '@'.$domain : '');
    }

    /** Status for the account page / portal profile. */
    public static function status(string $scope, Model $account): array
    {
        $enabled = TwoFactor::enabled($account);

        return [
            'migrated' => TwoFactor::migrated(self::table($scope)),
            'allowed' => self::featureEnabled($scope),
            'enabled' => $enabled,
            'method' => $enabled ? TwoFactor::method($account) : null,
            'confirmed_at' => $enabled ? optional($account->two_factor_confirmed_at)->toDateTimeString() : null,
            'recovery_codes_left' => $enabled && TwoFactor::method($account) === 'app' ? TwoFactor::recoveryCodesLeft($account) : 0,
        ];
    }

    /** Same rule as the sign-in form: the portal account is enabled. */
    private static function active(string $scope, Model $account): bool
    {
        return (int) $account->status === 1;
    }

    private static function table(string $scope): string
    {
        return 'portal_clients';
    }

    private static function pendingKey(string $scope): string
    {
        return $scope.'_two_factor_login';
    }

    private static function setupKey(string $scope): string
    {
        return $scope.'_two_factor_setup';
    }
}
