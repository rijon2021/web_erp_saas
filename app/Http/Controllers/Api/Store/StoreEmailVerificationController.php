<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Mail\StoreVerifyEmailMail;
use App\Models\EcommerceClient;
use App\Services\LicenseKeys\TenantMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Storefront e-mail verification. Store registration does not prove the
 * shopper owns the address, and guest orders are linked to an existing
 * customer by e-mail, so anything secret shown in the account (software
 * license keys) waits until the address is verified through a signed link.
 */
class StoreEmailVerificationController extends Controller
{
    /** Is the signed-in shopper's address verified? */
    public static function verified(?EcommerceClient $user): bool
    {
        return $user !== null && $user->email_verified_at !== null;
    }

    // POST /my/email/verify — send (or resend) the link
    public function send(Request $request)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            abort(401);
        }
        if (self::verified($user)) {
            return back()->with('license_status', __('messages.Verify_Already'));
        }
        if (! TenantMailer::configure()) {
            return back()->withErrors(['verify' => __('messages.Verify_No_Mail')]);
        }

        $url = URL::temporarySignedRoute('store.verify_email', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1(strtolower(trim((string) $user->email))),
        ]);

        try {
            Mail::to($user->email)->send(new StoreVerifyEmailMail($url, (string) ($user->username ?? '')));
        } catch (\Throwable $e) {
            Log::warning('Store verify email failed: '.$e->getMessage());

            return back()->withErrors(['verify' => __('messages.Verify_Send_Failed')]);
        }

        return back()->with('license_status', __('messages.Verify_Sent', ['email' => $user->email]));
    }

    // GET /account/verify-email/{id}/{hash}?expires=&signature=
    public function verify(Request $request, $id, $hash)
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('messages.Verify_Link_Invalid'));
        }
        $user = EcommerceClient::whereNull('deleted_at')->findOrFail((int) $id);
        // The link is for the address it was sent to: a changed e-mail needs a new link.
        if (! hash_equals(sha1(strtolower(trim((string) $user->email))), (string) $hash)) {
            abort(403, __('messages.Verify_Link_Invalid'));
        }
        // Must be opened by the account it belongs to (or while signed out).
        $current = Auth::guard('store')->user();
        if ($current && (int) $current->id !== (int) $user->id) {
            abort(403, __('messages.Verify_Link_Invalid'));
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->route('account.licenses')->with('license_status', __('messages.Verify_Done'));
    }
}
