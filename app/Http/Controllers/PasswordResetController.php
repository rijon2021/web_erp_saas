<?php

namespace App\Http\Controllers;

use App\Mail\Password_Reset_Request;
use App\Mail\Password_Reset_Success;
use App\Models\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Legacy API password reset (api/password/create|reset, web password/find/{token}).
 *
 * Hardening:
 *  - the API routes are disabled (routes/tenant_api.php); find/{token} remains;
 *  - create() answers the same way whether or not the e-mail exists;
 *  - only a SHA-256 of the token is stored (a DB read does not yield usable
 *    links; the token is 60 random chars, so a fast hash is sufficient and
 *    keeps find/{token} a direct lookup);
 *  - reset() enforces the 60-minute expiry and a strong password, and no
 *    longer returns the user record.
 */
class PasswordResetController extends BaseController
{
    private const EXPIRES_MINUTES = 60;

    private const SENT_MESSAGE = 'If an account exists for that e-mail address, we have e-mailed a password reset link.';

    private const INVALID_MESSAGE = 'This password reset token is invalid.';

    /**
     * Create token password reset
     */
    public function create(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && $user->statut && ! $user->deleted_at) {
            $token = Str::random(60);

            PasswordReset::updateOrCreate(
                ['email' => $user->email],
                [
                    'email' => $user->email,
                    'token' => self::hashToken($token),
                ]
            );

            $this->Set_config_mail(); // Set_config_mail => BaseController
            $url = url('/password/find/'.$token);
            Mail::to($user->email)->send(new Password_Reset_Request($token, $url));
        }

        // Same answer for unknown, inactive and valid accounts (no enumeration).
        return response()->json(['status' => true,
            'message' => self::SENT_MESSAGE,
        ], 200);
    }

    /**
     * Find token password reset
     */
    public function find($token)
    {
        $passwordReset = PasswordReset::where('token', self::hashToken((string) $token))->first();

        if (! $passwordReset || $this->expired($passwordReset)) {
            optional($passwordReset)->delete();

            return response()->json([
                'message' => self::INVALID_MESSAGE,
                'success' => false,
            ]);
        }

        return view('auth.passwords.reset', ['token' => $token]);
    }

    /**
     * Reset password
     */
    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'token' => 'required|string',
        ]);

        $passwordReset = PasswordReset::where([
            ['token', self::hashToken($request->token)],
            ['email', $request->email],
        ])->first();

        $user = $passwordReset ? User::where('email', $request->email)->first() : null;

        if (! $passwordReset || ! $user || $this->expired($passwordReset)) {
            optional($passwordReset)->delete();

            return response()->json([
                'message' => self::INVALID_MESSAGE,
                'status' => false,
                'code' => 3,
            ]);
        }

        $user->password = bcrypt($request->password);
        $user->save();
        $passwordReset->delete();

        // Existing logins (stolen or not) end with the old password.
        \App\Support\Security\RevokeUserAccess::all((int) $user->id);

        $this->Set_config_mail(); // Set_config_mail => BaseController
        Mail::to($user->email)->send(new Password_Reset_Success);

        return response()->json([
            'message' => 'Your Password has been changed.',
            'status' => true,
            'code' => 1,
        ]);
    }

    private function expired(PasswordReset $reset): bool
    {
        return Carbon::parse($reset->updated_at)->addMinutes(self::EXPIRES_MINUTES)->isPast();
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
