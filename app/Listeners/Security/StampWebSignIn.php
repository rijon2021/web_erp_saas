<?php

namespace App\Listeners\Security;

use App\Support\Security\ForceLogout;
use Illuminate\Auth\Events\Login;

/**
 * Records when a staff browser session signed in, so a super admin "force
 * logout" can tell sessions from before it apart from new ones. Fires for the
 * login form, impersonation and remember-me alike.
 */
class StampWebSignIn
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        try {
            ForceLogout::stampWebSignIn(request());
        } catch (\Throwable $e) {
            // Never break a login over this.
        }
    }
}
