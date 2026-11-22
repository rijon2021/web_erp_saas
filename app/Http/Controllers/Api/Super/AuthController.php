<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralApiToken;
use App\Models\Central\CentralUser;
use App\Models\Central\GeneralSetting;
use App\Support\Security\LoginGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the super-admin mobile app. Uses the same failed-attempt
 * lockout as the web login (Settings > Security). The CAPTCHA is a web-form
 * widget and is not asked here, exactly like the workspace mobile login.
 */
class AuthController extends Controller
{
    public const API_VERSION = 1;

    /** Public handshake: lets the app confirm an address is a Stocky platform. */
    public function ping()
    {
        $settings = GeneralSetting::instance();

        return response()->json([
            'ok'          => true,
            'server'      => 'stocky',
            'kind'        => 'platform',
            'api_version' => self::API_VERSION,
            'app_name'    => $settings->app_name ?: config('app.name'),
            'logo'        => $settings->getLogoUrl(),
            'currency'    => [
                'code'   => GeneralSetting::currencyCode(),
                'symbol' => GeneralSetting::currencySymbol(),
            ],
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'       => ['required', 'string', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:191'],
        ]);

        $guard = LoginGuard::central();
        $email = (string) $request->input('email');

        try {
            $guard->ensureNotLocked($request, $email);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => collect($e->errors())->flatten()->first(),
            ], 429);
        }

        $user = CentralUser::where('email', $email)->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => $guard->recordFailure($request, $email),
            ], 401);
        }

        $guard->recordSuccess($request, $email, (int) $user->id);

        [, $plain] = CentralApiToken::issue($user, $request->input('device_name') ?: 'Mobile app');

        return response()->json([
            'status'       => true,
            'token_type'   => 'Bearer',
            'access_token' => $plain,
            'user'         => $this->userPayload($user),
            'permissions'  => $this->permissionsFor($user),
        ]);
    }

    public function me()
    {
        $user = Auth::guard('central')->user();

        return response()->json([
            'user'        => $this->userPayload($user),
            'permissions' => $this->permissionsFor($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->attributes->get('central_api_token')?->delete();

        return response()->json(['status' => true]);
    }

    private function userPayload(CentralUser $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'role'           => $user->role,
            'is_super_admin' => $user->isSuperAdmin(),
            'avatar'         => $user->avatar ? asset($user->avatar) : null,
        ];
    }

    /** ['*'] for a super admin, else the granular page keys granted. */
    private function permissionsFor(CentralUser $user): array
    {
        return $user->isSuperAdmin() ? ['*'] : $user->effectivePermissions();
    }
}
