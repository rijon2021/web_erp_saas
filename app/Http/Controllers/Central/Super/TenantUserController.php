<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ImpersonationController;
use App\Models\Central\ImpersonationLog;
use App\Models\User;
use App\Support\Security\ForceLogout;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TenantUserController extends Controller
{
    /**
     * GET /super/tenants/{tenant}/users
     * List all users for a given tenant.
     */
    public function index(Request $request, Tenant $tenant): View
    {
        $tenant->load('domains');

        $users = [];
        $error = null;

        try {
            $tenant->run(function () use (&$users, $request) {
                $query = User::with('roles');

                if ($search = $request->input('search')) {
                    $query->where(function ($q) use ($search) {
                        $q->where('firstname', 'like', "%{$search}%")
                          ->orWhere('lastname', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('username', 'like', "%{$search}%");
                    });
                }

                if ($request->has('status') && $request->input('status') !== '') {
                    $query->where('statut', (int) $request->input('status'));
                }

                $users = $query->orderBy('id')->get();
            });
        } catch (\Throwable $e) {
            $error = 'Could not connect to tenant database. The workspace may not be provisioned yet.';
        }

        return view('central.super.tenants.users', compact('tenant', 'users', 'error'));
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/block
     */
    public function block(Tenant $tenant, int $userId): RedirectResponse
    {
        $userName = '';
        $result = null;

        $tenant->run(function () use ($tenant, $userId, &$userName, &$result) {
            $user = User::findOrFail($userId);
            $user->statut = 0; // explicit: statut is not mass assignable
            $user->save();
            $userName = trim($user->firstname . ' ' . $user->lastname);

            // A blocked user must not keep the sessions already open. The block
            // itself is saved by now, so a failure here must not turn into a 500.
            try {
                $result = $this->signOutEverywhere($tenant, $userId);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        $redirect = back()->with('success', __('super.flash.user_blocked', ['name' => $userName]));

        if ($result === null) {
            return $redirect->with('error', __('central.ForceLogoutFailed'));
        }

        Log::info('[super] force logout (block)', $this->auditContext($tenant, $userId, $result['users']));

        return $result['cutoff_applied']
            ? $redirect
            : $redirect->with('error', __('central.ForceLogoutNeedsMigration'));
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/unblock
     */
    public function unblock(Tenant $tenant, int $userId): RedirectResponse
    {
        $userName = '';

        $tenant->run(function () use ($userId, &$userName) {
            $user = User::findOrFail($userId);
            $user->statut = 1; // explicit: statut is not mass assignable
            $user->save();
            $userName = trim($user->firstname . ' ' . $user->lastname);
        });

        return back()->with('success', __('super.flash.user_unblocked', ['name' => $userName]));
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/password
     */
    public function changePassword(Request $request, Tenant $tenant, int $userId): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $userName = '';
        $hashed = Hash::make($validated['password']);

        $tenant->run(function () use ($userId, $hashed, &$userName) {
            $user = User::findOrFail($userId);
            $user->update(['password' => $hashed]);
            $userName = trim($user->firstname . ' ' . $user->lastname);
        });

        return back()->with('success', __('central.PasswordChangedFor', ['name' => $userName]));
    }

    /**
     * POST /super/tenants/{tenant}/force-logout
     *
     * Sign every user of the tenant out of every device and browser.
     */
    public function forceLogoutAll(Tenant $tenant): RedirectResponse
    {
        return $this->forceLogout($tenant, null);
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/force-logout
     */
    public function forceLogoutUser(Tenant $tenant, int $userId): RedirectResponse
    {
        return $this->forceLogout($tenant, $userId);
    }

    private function forceLogout(Tenant $tenant, ?int $userId): RedirectResponse
    {
        $result = null;
        $userName = null;
        $originalTenant = tenant();

        try {
            $tenant->run(function () use ($tenant, $userId, &$result, &$userName) {
                if ($userId !== null) {
                    $user = User::find($userId);
                    if (! $user) {
                        return;
                    }
                    $userName = trim($user->firstname . ' ' . $user->lastname);
                }

                $result = $this->signOutEverywhere($tenant, $userId);
            });
        } catch (\Throwable $e) {
            report($e);

            // Tenant::run() has no finally: don't finish this central request
            // on the tenant's database.
            if ($originalTenant === null && tenancy()->initialized) {
                tenancy()->end();
            }

            return back()->with('error', __('central.ForceLogoutFailed'));
        }

        if ($result === null) {
            return back()->with('error', __('central.ImpersonateUserNotFound'));
        }

        Log::info('[super] force logout', $this->auditContext($tenant, $userId, $result['users']));

        $message = $userId !== null
            ? __('central.ForceLogoutUserDone', ['name' => $userName])
            : __('central.ForceLogoutAllDone', ['count' => $result['users']]);

        $redirect = back()->with('success', $message);

        return $result['cutoff_applied']
            ? $redirect
            : $redirect->with('error', __('central.ForceLogoutNeedsMigration'));
    }

    /**
     * Sign the user (or every user) out of every device. Must run in the
     * tenant's context.
     *
     * @return array{users: int, cutoff_applied: bool}
     */
    private function signOutEverywhere(Tenant $tenant, ?int $userId): array
    {
        $result = ForceLogout::revoke($userId);

        // A super admin's "Login as user" session is one of those sessions:
        // close its audit trail too.
        ImpersonationLog::query()
            ->where('tenant_id', (string) $tenant->id)
            ->where('status', ImpersonationLog::STATUS_ACTIVE)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->each(fn (ImpersonationLog $log) => $log->end('force_logout'));
        ImpersonationController::clearCacheFlagIfIdle();

        return $result;
    }

    private function auditContext(Tenant $tenant, ?int $userId, int $users): array
    {
        $admin = Auth::guard('central')->user();

        return [
            'tenant_id' => (string) $tenant->id,
            'user_id'   => $userId,
            'users'     => $users,
            'admin_id'  => $admin?->id,
            'admin'     => $admin?->email,
        ];
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/impersonate
     *
     * Issue a single-use, short-lived login token for the tenant user and
     * redirect the admin to the tenant domain, where the token is exchanged
     * for a normal web session. The user's password is never read or changed.
     */
    public function impersonate(Request $request, Tenant $tenant, int $userId): RedirectResponse
    {
        if (! $tenant->isActive()) {
            return back()->with('error', __('central.ImpersonateTenantNotActive'));
        }

        $tenantUrl = $tenant->getTenantUrl();
        if (! $tenantUrl) {
            return back()->with('error', __('central.ImpersonateNoDomain'));
        }

        // Resolve the target user inside the tenant DB (password stays untouched).
        $target = null;
        try {
            $tenant->run(function () use ($userId, &$target) {
                if ($user = User::find($userId)) {
                    $target = [
                        'name'   => trim($user->firstname . ' ' . $user->lastname),
                        'email'  => (string) $user->email,
                        'statut' => (int) $user->statut,
                    ];
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', __('central.ImpersonateTenantDbError'));
        }

        if (! $target) {
            return back()->with('error', __('central.ImpersonateUserNotFound'));
        }

        if ($target['statut'] !== 1) {
            return back()->with('error', __('central.ImpersonateUserBlocked'));
        }

        $admin = Auth::guard('central')->user();
        $token = Str::random(64);

        ImpersonationLog::create([
            'token_hash'      => hash('sha256', $token),
            'central_user_id' => $admin->id,
            'admin_name'      => (string) $admin->name,
            'admin_email'     => (string) $admin->email,
            'tenant_id'       => (string) $tenant->id,
            'tenant_name'     => $tenant->company_name ?? ($tenant->domains()->first()->domain ?? $tenant->id),
            'user_id'         => $userId,
            'user_name'       => $target['name'],
            'user_email'      => $target['email'],
            'ip_address'      => $request->ip(),
            'user_agent'      => Str::limit((string) ($request->userAgent() ?? ''), 490, ''),
            'status'          => ImpersonationLog::STATUS_PENDING,
        ]);

        return redirect()->away($tenantUrl . '/impersonate/' . $token);
    }
}
