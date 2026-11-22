<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\BaseController;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Services\TenantLimitsService;
use App\Support\PlanPermissionMap;
use App\Support\Security\LoginGuard;
use App\Support\Security\SingleSession;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * Auth endpoints for the workspace mobile app (Flutter).
 *
 * The app talks to one workspace at a time, on that workspace's own domain, so
 * these routes run in tenant context like the rest of tenant_api.php. Unlike
 * the SPA — which signs in through the web session and rides the laravel_token
 * cookie — the app authenticates every call with a Passport personal access
 * token issued here and sent as a Bearer header. That token flows through the
 * same auth:api guard and token.timeout bookkeeping as the SPA, so devices
 * appear under Login Devices and can be revoked there.
 *
 * Deliberately NOT behind tenant.subscribed (same as get_user_auth): a lapsed
 * workspace must still be able to sign in and be told why the app is locked.
 * Every data endpoint the app calls stays behind the normal gates.
 */
class MobileAuthController extends BaseController
{
    /**
     * Public handshake used to validate a workspace address typed into the
     * app, and to negotiate versions before login. Deliberately exposes only
     * data that is already public on the workspace's login page.
     */
    public function ping()
    {
        $settings = Setting::whereNull('deleted_at')->first();
        $helpers = new helpers;

        // Admin-configured mobile settings (System Settings → Mobile App).
        // Each falls back to the general setting when left empty.
        $mobileEnabled = (bool) ($settings->mobile_app_enabled ?? true);
        $minVersion = ($settings->mobile_min_version ?? null)
            ?: (string) config('mobile.min_app_version');

        return response()->json([
            'ok' => true,
            'server' => 'stocky',
            'api_version' => (int) config('mobile.api_version'),
            'min_app_version' => $minVersion,
            'app_name' => ($settings->mobile_app_name ?? null)
                ?: ($settings->app_name ?? config('app.name')),
            // Root-relative URL of a file that exists; the app uses it as is.
            'logo' => receipt_logo_url(($settings->mobile_logo ?? null) ?: ($settings->logo ?? null)),
            // Uploads hang off this folder (images/tenants/{id}); the app
            // appends "/<folder>/<file>" exactly like the SPA's uploadUrl().
            'upload_path' => upload_path(),
            'default_language' => $settings->default_language ?? 'en',
            'currency' => [
                'code' => $helpers->Get_Currency_Code(),
                'symbol' => $helpers->Get_Currency(),
            ],
            // The app disables itself (and shows the message) when off.
            'mobile_enabled' => $mobileEnabled,
            'maintenance_message' => $settings->mobile_maintenance_message ?? null,
            'branding' => [
                'primary_color' => ($settings->mobile_primary_color ?? null) ?: null,
                'theme_mode' => ($settings->mobile_theme_mode ?? null) ?: 'system',
            ],
            'features' => [
                'offline' => (bool) ($settings->mobile_offline_enabled ?? true),
                'scanner' => (bool) ($settings->mobile_scanner_enabled ?? true),
                'price_edit' => (bool) ($settings->mobile_allow_price_edit ?? true),
            ],
            'modules' => ($settings && $settings->mobile_modules)
                ? json_decode($settings->mobile_modules, true)
                : null,
            'support' => [
                'phone' => ($settings->mobile_support_phone ?? null) ?: null,
                'email' => ($settings->mobile_support_email ?? null) ?: null,
            ],
        ]);
    }

    // --------------- Login (issues a Bearer token) ----------------\\

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:191',
        ]);

        // Same failed-attempt lockout as the web login (System Settings >
        // Security), so the app's endpoint can't be used to guess around it.
        $guard = LoginGuard::tenant();
        $email = (string) $request->input('email');
        try {
            $guard->ensureNotLocked($request, $email);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first(),
            ], 429);
        }

        // Credentials are checked directly against the user row instead of
        // Auth::attempt(): the api middleware group has no session store
        // for the session guard to write to.
        $user = User::where('email', $request->email)
            ->whereNull('deleted_at')
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => false,
                // Generic error, or the lockout notice when this failure triggered it.
                'message' => $guard->recordFailure($request, $email),
            ], 401);
        }

        if (! (bool) $user->statut) {
            return response()->json([
                'status' => 'NotActive',
                'message' => 'This user not active',
            ], 403);
        }

        // System Settings → Mobile App can switch the app off for the workspace.
        $settings = Setting::whereNull('deleted_at')->first();
        if (! (bool) ($settings->mobile_app_enabled ?? true)) {
            return response()->json([
                'status' => 'AppDisabled',
                'message' => ($settings->mobile_maintenance_message ?? null)
                    ?: 'The mobile app is currently unavailable. Please contact your administrator.',
            ], 403);
        }

        $guard->recordSuccess($request, $email, (int) $user->id);

        // One active session per user: the token issued below replaces every
        // other session and token of this user.
        if ($guard->options()['single_session']) {
            SingleSession::revokeOthers((int) $user->id);
            SingleSession::rotateRememberToken($user);
        }

        $this->ensurePersonalAccessClient();

        $deviceName = $request->input('device_name') ?: 'Mobile App';
        $accessToken = $user->createToken($deviceName)->accessToken;

        return response()->json([
            'status' => true,
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'user' => $this->userPayload($user),
            'permissions' => $this->permissionsFor($user),
            'subscription' => $this->subscriptionState(),
        ]);
    }

    // --------------- Current user (app refresh) ----------------\\

    public function me(Request $request)
    {
        $user = $request->user();

        // Switched off while signed in. Only the app calls `me`, so refuse it
        // even without the X-Stocky-Client header EnsureMobileAppEnabled reads.
        $settings = Setting::whereNull('deleted_at')->first();
        if (! (bool) ($settings->mobile_app_enabled ?? true)) {
            return response()->json([
                'status' => 'AppDisabled',
                'message' => \App\Http\Middleware\EnsureMobileAppEnabled::message($settings),
            ], 403);
        }

        return response()->json([
            'status' => true,
            'user' => $this->userPayload($user),
            'permissions' => $this->permissionsFor($user),
            'subscription' => $this->subscriptionState(),
        ]);
    }

    // --------------- Logout (revokes this device's token) ----------------\\

    public function logout(Request $request)
    {
        $token = $request->user()->token();

        if ($token instanceof Token) {
            // Mark the session revoked first so the device list stays in
            // sync even if the Passport revoke below were to fail.
            try {
                UserLoginSession::where('access_token_id', (string) $token->getKey())
                    ->update(['revoked_at' => now()]);
            } catch (\Throwable $e) {
                // best-effort bookkeeping
            }

            $token->revoke();
        }

        return response()->json(['status' => true]);
    }

    /**
     * Workspaces can exist without a Passport personal access client (the web
     * app runs on cookies, so nothing needs one until the first app sign-in),
     * and createToken() throws without it.
     */
    private function ensurePersonalAccessClient(): void
    {
        if (! Passport::personalAccessClient()->exists()) {
            app(ClientRepository::class)->createPersonalAccessClient(
                null,
                'Laravel Personal Access Client',
                config('app.url') ?: 'http://localhost'
            );
        }
    }

    /**
     * The role's permissions minus everything the workspace's plan does not
     * unlock, so the app hides those screens instead of letting each one fail
     * with a 403 from tenant.feature. A role can still hold such a permission
     * after a downgrade; PlanPermissionMap is what the role editor enforces.
     */
    private function permissionsFor(User $user): array
    {
        $permissions = $user->roles()->first()?->permissions->pluck('name')->all() ?? [];
        $plan = app(TenantLimitsService::class)->getActivePlan();

        return array_values(array_diff($permissions, PlanPermissionMap::lockedFor($plan)));
    }

    /**
     * Whether the workspace can use the app right now, mirroring the checks in
     * EnsureTenantNotSuspended and EnsureActiveSubscription:
     *
     *  - active:    everything works.
     *  - read_only: a paid period ended and the grace period only allows reads.
     *  - locked:    every data endpoint answers 403 subscription_required.
     *
     * @return array{state: string, message: ?string}
     */
    private function subscriptionState(): array
    {
        $tenant = tenant();

        if ($tenant->isSuspendedForNonPayment()) {
            return ['state' => 'locked', 'message' => __('central.WorkspaceUnpaidMessage')];
        }

        // getActivePlan() counts grace rows, so null means no access at all.
        if (! app(TenantLimitsService::class)->getActivePlan()) {
            return ['state' => 'locked', 'message' => EnsureActiveSubscription::MESSAGE];
        }

        $paidThrough = TenantSubscription::where('tenant_id', $tenant->id)->grantingAccess(false)->exists();
        if (! $paidThrough && GeneralSetting::instance()->subscriptionGraceMode() === 'read_only') {
            $graceRow = TenantSubscription::where('tenant_id', $tenant->id)->inGrace()->latest('id')->first();
            if ($graceRow) {
                return [
                    'state' => 'read_only',
                    'message' => __('central.GraceReadOnlyMessage', ['date' => $graceRow->graceEndsAt()?->format('M d, Y')]),
                ];
            }
        }

        return ['state' => 'active', 'message' => null];
    }

    /** Profile + display settings the app needs at boot; mirrors GetUserAuth. */
    private function userPayload(User $user): array
    {
        $settings = Setting::whereNull('deleted_at')->first();
        $helpers = new helpers;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'app_name' => $settings->app_name ?? config('app.name'),
            'logo' => receipt_logo_url($settings->logo ?? null),
            'company' => $settings->CompanyName ?? '',
            'default_language' => $settings->default_language ?? 'en',
            'currency' => [
                'code' => $helpers->Get_Currency_Code(),
                'symbol' => $helpers->Get_Currency(),
            ],
            'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
            'price_format' => $settings->price_format ?? null,
            'price_decimals' => (bool) ($settings->enable_3_decimal_pricing ?? false) ? 3 : 2,
            'timezone' => $settings->timezone ?? 'UTC',
            // Admin module toggles ({key: bool}, null = everything enabled) —
            // the app gates its navigation with these plus the permissions list.
            'module_flags' => ($settings && $settings->module_flags)
                ? json_decode($settings->module_flags, true)
                : null,
            // Mobile-only module switches from System Settings → Mobile App.
            'mobile_modules' => ($settings && $settings->mobile_modules)
                ? json_decode($settings->mobile_modules, true)
                : null,
            'mobile_features' => [
                'offline' => (bool) ($settings->mobile_offline_enabled ?? true),
                'scanner' => (bool) ($settings->mobile_scanner_enabled ?? true),
                'price_edit' => (bool) ($settings->mobile_allow_price_edit ?? true),
            ],
            'mobile_primary_color' => ($settings->mobile_primary_color ?? null) ?: null,
            'mobile_theme_mode' => ($settings->mobile_theme_mode ?? null) ?: 'system',
        ];
    }
}
