<?php

namespace App\Listeners\Security;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Sign-in / sign-out / failed sign-in → audit log (tenant users only; the
 * recorder is a no-op outside tenancy and on plans without audit_log).
 */
class AuditAuthEvents
{
    /** Staff guards only — the storefront and portal sign in shoppers through their own. */
    private const GUARDS = ['web', 'api'];

    public function login(Login $event): void
    {
        if (in_array($event->guard, self::GUARDS, true) && $event->user instanceof User) {
            app(AuditRecorder::class)->auth('login', $event->user);
        }
    }

    public function logout(Logout $event): void
    {
        if (in_array($event->guard, self::GUARDS, true) && $event->user instanceof User) {
            app(AuditRecorder::class)->auth('logout', $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        if (! in_array($event->guard, self::GUARDS, true) || ($event->user !== null && ! $event->user instanceof User)) {
            return;
        }
        $login = (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? '');

        app(AuditRecorder::class)->auth('login_failed', $event->user, $login);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
        ];
    }
}
