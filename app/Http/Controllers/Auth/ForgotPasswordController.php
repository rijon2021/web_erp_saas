<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use App\Support\Security\LoginGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends BaseController
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    /**
     * Display the form to request a password reset link.
     */
    public function showLinkRequestForm()
    {
        $guard = LoginGuard::tenant();

        return view('auth.passwords.email', [
            'captcha' => $guard->captchaOnForgotPassword() ? $guard->captcha()->widget() : null,
        ]);
    }

    /**
     * Handle sending a reset link to the given email.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // System Settings > Security: CAPTCHA on this form stops it being used
        // to flood a mailbox with reset emails.
        $guard = LoginGuard::tenant();
        if ($guard->captchaOnForgotPassword()) {
            $guard->verifyCaptcha($request);
        }

        // Set mail configuration from database before sending email
        $this->Set_config_mail();

        $status = Password::sendResetLink($request->only('email'));

        // Same answer whether or not the e-mail belongs to an account (and
        // whether a link was already sent recently): the form must not reveal
        // which addresses exist.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with(['status' => __(Password::RESET_LINK_SENT)]);
        }

        return back()->withErrors(['email' => __($status)]);
    }
}
