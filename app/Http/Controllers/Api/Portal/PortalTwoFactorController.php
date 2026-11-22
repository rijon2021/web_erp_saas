<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Support\Security\CustomerTwoFactor;
use App\Support\Security\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Client portal two-step sign-in (JSON for the portal SPA):
 *  - the code step after a correct password (PortalAuthController::login
 *    answers {two_factor: true, method, email} and parks the account);
 *  - turning it on/off from the portal Profile page.
 * Client Portal → Portal Settings → "Two-step verification" allows turning it on.
 */
class PortalTwoFactorController extends Controller
{
    private const SCOPE = 'portal';

    // ------------------------------------------------------------ sign-in step

    /** POST /api/portal/login/two-factor {code, trust_device} */
    public function verify(Request $request)
    {
        $pending = CustomerTwoFactor::pending($request, self::SCOPE);
        if (! $pending) {
            return response()->json(['message' => __('messages.TwoFactorExpired'), 'expired' => true], 422);
        }
        [$account, $data] = $pending;

        $request->validate(['code' => ['required', 'max:32']]);

        if ($error = CustomerTwoFactor::verifyChallenge($account, (string) $request->input('code'))) {
            return response()->json(['message' => $error], 422);
        }

        if ((int) $account->status !== 1) {
            CustomerTwoFactor::clearPending($request, self::SCOPE);

            return response()->json(['message' => __('portal.portal_disabled_account')], 403);
        }

        CustomerTwoFactor::clearPending($request, self::SCOPE);
        // No session regenerate: the admin app shares this session (see PortalAuthController).
        Auth::guard('portal')->login($account, (bool) ($data['remember'] ?? false));

        // Same as a normal sign-in: the language picked on the login page
        // becomes the client's saved preference.
        $chosen = session(\App\Http\Middleware\SetPortalLocale::SESSION_KEY);
        if ($chosen && isset(\App\Services\PortalLanguageInstaller::activeLocales()[$chosen]) && $account->preferred_locale !== $chosen) {
            $account->forceFill(['preferred_locale' => $chosen])->save();
        }

        $response = response()->json(['success' => true]);
        if ($request->boolean('trust_device')) {
            $response->withCookie(TwoFactor::trustCookie($account, TwoFactor::CUSTOMER_TRUST_DAYS));
        }

        return $response;
    }

    /** POST /api/portal/login/two-factor/resend — email method only. */
    public function resend(Request $request)
    {
        $pending = CustomerTwoFactor::pending($request, self::SCOPE);
        if (! $pending) {
            return response()->json(['message' => __('messages.TwoFactorExpired'), 'expired' => true], 422);
        }

        return $this->sendResponse(TwoFactor::sendEmailCode($pending[0], CustomerTwoFactor::brand(self::SCOPE)));
    }

    // ------------------------------------------------------------ profile

    /** GET /api/portal/profile/two-factor */
    public function status(Request $request)
    {
        $account = Auth::guard('portal')->user();

        return response()->json(CustomerTwoFactor::status(self::SCOPE, $account) + [
            'email' => CustomerTwoFactor::maskEmail((string) $account->email),
            'trust_days' => TwoFactor::CUSTOMER_TRUST_DAYS,
        ]);
    }

    /** POST /api/portal/profile/two-factor/setup {method, password} */
    public function setup(Request $request)
    {
        $account = Auth::guard('portal')->user();
        $data = $request->validate([
            'method' => ['required', 'in:email,app'],
            'password' => ['required', 'string'],
        ]);

        if (! CustomerTwoFactor::featureEnabled(self::SCOPE)) {
            // 422, not 403: the portal SPA treats any 403 as "signed out".
            return response()->json(['message' => __('messages.TwoFactorNotAvailable')], 422);
        }
        if (TwoFactor::enabled($account)) {
            return response()->json(['message' => __('messages.TwoFactorAlreadyOn')], 422);
        }
        if (! Hash::check($data['password'], $account->password)) {
            return response()->json(['message' => __('messages.TwoFactorWrongPassword')], 422);
        }

        $result = CustomerTwoFactor::startSetup($request, self::SCOPE, $account, $data['method']);
        if ($data['method'] === 'email' && $result['sent'] !== 0) {
            return $this->sendResponse($result['sent']);
        }

        return response()->json($result);
    }

    /** POST /api/portal/profile/two-factor/resend — new email code during setup. */
    public function resendSetup(Request $request)
    {
        $account = Auth::guard('portal')->user();
        $setup = CustomerTwoFactor::setupInProgress($request, self::SCOPE, $account);
        if (! $setup || $setup['method'] !== 'email') {
            return response()->json(['message' => __('messages.TwoFactorExpired')], 422);
        }

        return $this->sendResponse(TwoFactor::sendEmailCode($account, CustomerTwoFactor::brand(self::SCOPE)));
    }

    /** POST /api/portal/profile/two-factor/confirm {code} */
    public function confirm(Request $request)
    {
        $account = Auth::guard('portal')->user();
        $request->validate(['code' => ['required', 'max:16']]);

        $setup = CustomerTwoFactor::setupInProgress($request, self::SCOPE, $account);
        $codes = CustomerTwoFactor::confirmSetup($request, self::SCOPE, $account, (string) $request->input('code'));
        if ($codes === null) {
            return response()->json(['message' => $setup && $setup['method'] === 'email'
                ? __('messages.TwoFactorEmailCodeInvalid')
                : __('messages.TwoFactorCodeInvalid')], 422);
        }

        return response()->json(CustomerTwoFactor::status(self::SCOPE, $account->fresh()) + ['recovery_codes' => $codes]);
    }

    /** POST /api/portal/profile/two-factor/recovery-codes {password} */
    public function regenerate(Request $request)
    {
        $account = Auth::guard('portal')->user();
        if ($error = $this->checkPassword($request, $account)) {
            return $error;
        }
        if (! TwoFactor::enabled($account) || TwoFactor::method($account) !== 'app') {
            return response()->json(['message' => __('messages.TwoFactorExpired')], 422);
        }

        $codes = TwoFactor::regenerateRecoveryCodes($account);

        return response()->json(CustomerTwoFactor::status(self::SCOPE, $account->fresh()) + ['recovery_codes' => $codes]);
    }

    /** POST /api/portal/profile/two-factor/disable {password} */
    public function disable(Request $request)
    {
        $account = Auth::guard('portal')->user();
        if ($error = $this->checkPassword($request, $account)) {
            return $error;
        }

        TwoFactor::disable($account);
        CustomerTwoFactor::cancelSetup($request, self::SCOPE);

        return response()->json(CustomerTwoFactor::status(self::SCOPE, $account->fresh()));
    }

    // ------------------------------------------------------------ helpers

    private function checkPassword(Request $request, $account)
    {
        $request->validate(['password' => ['required', 'string']]);

        return Hash::check((string) $request->input('password'), $account->password)
            ? null
            : response()->json(['message' => __('messages.TwoFactorWrongPassword')], 422);
    }

    private function sendResponse(int $sent)
    {
        if ($sent === 0) {
            return response()->json(['sent' => true, 'method' => 'email']);
        }

        return response()->json(['message' => $sent > 0
            ? __('messages.TwoFactorResendWait', ['seconds' => $sent])
            : __('messages.TwoFactorEmailFailed')], $sent > 0 ? 429 : 422);
    }
}
