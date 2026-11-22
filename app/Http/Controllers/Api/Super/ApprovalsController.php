<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Central\Super\PaymentController as WebPaymentController;
use App\Http\Controllers\Central\Super\TenantController as WebTenantController;
use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Everything waiting for the admin: workspaces held for manual approval and
 * bank-transfer signups whose proof needs checking.
 */
class ApprovalsController extends Controller
{
    use RunsWebActions;

    public function index()
    {
        $user = Auth::guard('central')->user();
        $items = collect();

        if ($user->hasPermission('tenants')) {
            $items = $items->merge(
                Tenant::with(['domains', 'subscription.plan'])
                    ->where('status', Tenant::STATUS_PENDING)
                    ->orderByDesc('created_at')->limit(50)->get()
                    ->map(fn (Tenant $t) => SuperPresenter::pendingTenant($t))
            );
        }

        if ($user->hasPermission('payments_pending')) {
            $items = $items->merge(
                PendingRegistration::with('plan')
                    ->where('gateway', 'offline')
                    ->where('status', PendingRegistration::STATUS_PROCESSING)
                    ->orderByDesc('created_at')->limit(50)->get()
                    ->map(fn (PendingRegistration $r) => SuperPresenter::pendingRegistration($r))
            );
        }

        return response()->json([
            'data' => $items->sortByDesc('created_at')->values(),
        ]);
    }

    /**
     * Approve with the database settings the web form pre-fills on a VPS.
     * Shared hosting needs a database made by hand, so it stays on the web.
     */
    public function approveTenant(Request $request, Tenant $tenant)
    {
        if (GeneralSetting::instance()->isSharedHosting()) {
            return response()->json([
                'ok'      => false,
                'message' => 'On shared hosting the database must be created in your hosting panel first. Approve this workspace on the web.',
            ], 422);
        }

        $request->merge([
            'db_host'     => config('database.connections.central.host', '127.0.0.1'),
            'db_name'     => config('tenancy.database.prefix', 'tenant') . $tenant->id . config('tenancy.database.suffix', ''),
            'db_username' => config('database.connections.central.username', 'root'),
            'db_password' => config('database.connections.central.password', ''),
            'db_port'     => (int) config('database.connections.central.port', 3306),
        ]);

        return $this->runWebAction($request, fn () => app(WebTenantController::class)->approve($request, $tenant));
    }

    public function rejectTenant(Request $request, Tenant $tenant)
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runWebAction($request, fn () => app(WebTenantController::class)->reject($request, $tenant));
    }

    public function approveRegistration(Request $request, PendingRegistration $registration)
    {
        return $this->runWebAction($request, fn () => app(WebPaymentController::class)->approveRegistration($registration, $request));
    }

    public function rejectRegistration(Request $request, PendingRegistration $registration)
    {
        return $this->runWebAction($request, fn () => app(WebPaymentController::class)->rejectRegistration($registration));
    }
}
