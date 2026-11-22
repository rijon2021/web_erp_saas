<?php

namespace App\Http\Controllers;

use App\Support\Security\LoginGuard;
use App\Support\Security\SingleSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseController
{
    // --------------- Function Login ----------------\\

    public function getAccessToken(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        $credentials = request(['email', 'password']);

        // Same failed-attempt lockout as the login form (System Settings >
        // Security), so this endpoint can't be used to guess around it.
        $guard = LoginGuard::tenant();
        $email = (string) $request->input('email');
        try {
            $guard->ensureNotLocked($request, $email);
        } catch (ValidationException $e) {
            // Same JSON shape as the endpoint's other failures, never a redirect.
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'status' => false,
            ], 429);
        }

        if (Auth::attempt($credentials)) {
            $userStatus = Auth::User()->statut;
            if ($userStatus === 0) {
                return response()->json([
                    'message' => 'This user not active',
                    'status' => 'NotActive',
                ]);
            }
            $guard->recordSuccess($request, $email, (int) Auth::id());

            // One active session per user: the token issued below replaces
            // every other session and token of this user.
            if ($guard->options()['single_session']) {
                SingleSession::revokeOthers((int) Auth::id());
                SingleSession::rotateRememberToken(Auth::user());
            }

        } else {
            $guard->recordFailure($request, $email);

            return response()->json([
                'message' => 'Incorrect Login',
                'status' => false,
            ]);
        }

        $user = auth()->user();
        $tokenResult = $user->createToken('Access Token');
        $token = $tokenResult->token;
        $this->setCookie('Stocky_token', $tokenResult->accessToken);

        return response()->json([
            'Stocky_token' => $tokenResult->accessToken,
            'username' => Auth::User()->username,
            'status' => true,
        ]);
    }

    // --------------- Function Logout ----------------\\

    public function logout()
    {
        if (Auth::check()) {
            $user = Auth::user()->token();
            $user->revoke();
            $this->destroyCookie('Stocky_token');

            return response()->json('success');
        }

    }
}
