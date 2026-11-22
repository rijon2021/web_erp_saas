<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use App\Support\Security\LoginGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CentralLoginController extends Controller
{
    public function showLoginForm(Request $request)
    {
        if (Auth::guard('central')->check()) {
            return redirect()->route('super.dashboard');
        }

        $guard = LoginGuard::central();

        return view('central.login', [
            'captcha' => $guard->captchaRequired($request) ? $guard->captcha()->widget() : null,
        ]);
    }

    /** Built-in CAPTCHA image (super admin Settings > Security). */
    public function captcha(Request $request)
    {
        return response(LoginGuard::central()->captcha()->image($request), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Settings > Security: lockout, then CAPTCHA, then the password.
        $guard = LoginGuard::central();
        $email = (string) $request->input('email');

        $guard->ensureNotLocked($request, $email);
        if ($guard->captchaRequired($request)) {
            $guard->verifyCaptcha($request, $email);
        }

        $user = CentralUser::where('email', $email)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return back()->withErrors([
                'email' => $guard->recordFailure($request, $email),
            ])->withInput($request->only('email', 'remember'));
        }

        $guard->recordSuccess($request, $email, (int) $user->id);

        Auth::guard('central')->login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->route('super.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('central')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('central.login');
    }
}
