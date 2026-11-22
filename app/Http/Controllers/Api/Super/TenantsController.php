<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Central\Super\TenantController as WebTenantController;
use App\Http\Controllers\Central\Super\TenantUserController as WebTenantUserController;
use App\Http\Controllers\Central\Super\TenantSubscriptionPanelController as WebSubscriptionPanel;
use App\Http\Controllers\Central\Super\DomainController as WebDomainController;
use App\Http\Controllers\Central\Super\CustomDomainController as WebCustomDomainController;
use App\Services\CustomDomainManager;
use App\Support\TenantDomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Models\User;
use App\Tenant;
use Illuminate\Http\Request;

class TenantsController extends Controller
{
    use RunsWebActions;

    /** Filter chips the app shows, in order. */
    private const FILTERS = ['all', 'active', 'trial', 'pending', 'suspended', 'expiring'];

    public function index(Request $request)
    {
        TenantSubscription::expireLapsed();

        $filter = in_array($request->query('status'), self::FILTERS, true) ? $request->query('status') : 'all';

        $query = $this->filtered(Tenant::with(['domains', 'subscriptions.plan']), $filter);

        if ($search = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('id', 'like', $like)
                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.company_name'))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.admin_email'))) LIKE ?", [$like])
                  ->orWhereHas('domains', fn ($d) => $d->where('domain', 'like', $like));
            });
        }

        $page = $query->orderByDesc('created_at')->paginate(20);

        $counts = [];
        foreach (self::FILTERS as $f) {
            $counts[$f] = $this->filtered(Tenant::query(), $f)->count();
        }

        return response()->json([
            'data'   => collect($page->items())->map(fn (Tenant $t) => SuperPresenter::tenantSummary($t))->values(),
            'meta'   => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            'counts' => $counts,
        ]);
    }

    public function show(Tenant $tenant)
    {
        $tenant->load(['domains', 'customDomains', 'subscriptions.plan']);
        $sub = $tenant->currentSubscriptionLoaded();
        $live = ! in_array($tenant->status, [Tenant::STATUS_PENDING, Tenant::STATUS_PROVISIONING, Tenant::STATUS_FAILED, Tenant::STATUS_REJECTED], true);

        [$usage, $users, $userCount] = $live ? $this->fromTenantDatabase($tenant, $sub?->plan) : [[], [], 0];

        return response()->json([
            'tenant'   => SuperPresenter::tenantSummary($tenant) + [
                'owner_phone'      => $tenant->owner_phone,
                'locale'           => $tenant->locale,
                'rejection_reason' => $tenant->rejection_reason,
            ],
            'usage'    => $usage,
            'users'    => $users,
            'user_count' => $userCount,
            // Verified custom domains also have a mirror row in `domains`; list them once, as custom.
            'domains'  => $tenant->domains->reject(fn ($d) => TenantDomainStatus::isCustomDomainRow($d))->map(fn ($d) => [
                'id'         => $d->id,
                'domain'     => SuperPresenter::host($d->domain),
                'is_active'  => TenantDomainStatus::isActive($d),
                'kind'       => 'subdomain',
                'status'     => TenantDomainStatus::isActive($d) ? 'live' : 'off',
                'can_toggle' => TenantDomainStatus::supported(),
            ])->merge($tenant->customDomains->map(fn ($d) => [
                'id'        => $d->id,
                'domain'    => $d->domain,
                'is_active' => (bool) $d->is_verified,
                'kind'      => 'custom',
                'primary'   => (bool) $d->is_primary,
                'status'    => $d->is_verified ? (($d->ssl_status ?? null) === 'active' ? 'live' : 'ssl_pending') : 'verify_dns',
                'error'     => $d->last_check_error,
                'dns'       => $d->is_verified ? null : $this->dnsFor($d),
            ]))->values(),
            'payments' => $tenant->payments()->with(['plan', 'tenant'])->latest()->limit(5)->get()
                ->map(fn ($p) => SuperPresenter::payment($p))->values(),
            'actions'  => [
                'can_suspend'    => in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_CANCELLED], true),
                'can_reactivate' => $tenant->status === Tenant::STATUS_SUSPENDED,
                // Extend / change plan need a settled subscription, like the web panel.
                'can_change_subscription' => $live && $sub
                    && ! in_array($sub->status, [TenantSubscription::STATUS_PENDING, TenantSubscription::STATUS_FAILED], true),
            ],
        ]);
    }

    public function suspend(Request $request, Tenant $tenant)
    {
        if (! in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_CANCELLED], true)) {
            return response()->json(['ok' => false, 'message' => 'Only a live workspace can be suspended.'], 422);
        }

        return $this->runWebAction($request, fn () => app(WebTenantController::class)->suspend($tenant));
    }

    public function reactivate(Request $request, Tenant $tenant)
    {
        if ($tenant->status !== Tenant::STATUS_SUSPENDED) {
            return response()->json(['ok' => false, 'message' => 'This workspace is not suspended.'], 422);
        }

        return $this->runWebAction($request, fn () => app(WebTenantController::class)->reactivate($tenant));
    }

    // ── Subscription (same as the web Subscription panel) ─────────────

    /** Expects days (1–3650) and an optional note. */
    public function extend(Request $request, Tenant $tenant)
    {
        return $this->runWebAction($request, fn () => app(WebSubscriptionPanel::class)->extend($request, $tenant));
    }

    /** Expects plan_id, billing_cycle, period (keep|restart), optional amount and note. */
    public function changePlan(Request $request, Tenant $tenant)
    {
        return $this->runWebAction($request, fn () => app(WebSubscriptionPanel::class)->changePlan($request, $tenant));
    }

    // ── Domains (same as the web Domains panel) ───────────────────────

    /** kind=subdomain (a name on the platform) or kind=custom (the customer's own domain). */
    public function addDomain(Request $request, Tenant $tenant)
    {
        return $request->input('kind') === 'custom'
            ? $this->runWebAction($request, fn () => app(WebCustomDomainController::class)->store($request, $tenant))
            : $this->runWebAction($request, fn () => app(WebDomainController::class)->store($request, $tenant));
    }

    public function toggleDomain(Request $request, Tenant $tenant, int $domainId)
    {
        return $this->runWebAction($request, fn () => app(WebDomainController::class)->toggle($tenant, $domainId));
    }

    public function removeDomain(Request $request, Tenant $tenant, int $domainId)
    {
        return $this->runWebAction($request, fn () => app(WebDomainController::class)->destroy($tenant, $domainId));
    }

    public function verifyCustomDomain(Request $request, Tenant $tenant, int $id)
    {
        return $this->runWebAction($request, fn () => app(WebCustomDomainController::class)->verify($request, $tenant, $id));
    }

    public function checkCustomDomainSsl(Request $request, Tenant $tenant, int $id)
    {
        return $this->runWebAction($request, fn () => app(WebCustomDomainController::class)->checkSsl($tenant, $id));
    }

    public function primaryCustomDomain(Request $request, Tenant $tenant, int $id)
    {
        return $this->runWebAction($request, fn () => app(WebCustomDomainController::class)->makePrimary($tenant, $id));
    }

    public function removeCustomDomain(Request $request, Tenant $tenant, int $id)
    {
        return $this->runWebAction($request, fn () => app(WebCustomDomainController::class)->destroy($tenant, $id));
    }

    /** Active plans for the change-plan and record-payment forms. */
    public function plans()
    {
        return response()->json([
            'data' => Plan::where('is_active', true)->orderBy('price')->get()->map(fn (Plan $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'monthly'       => (float) $p->getPriceForCycle('monthly'),
                'yearly'        => (float) $p->getPriceForCycle('yearly'),
                'monthly_label' => SuperPresenter::money($p->getPriceForCycle('monthly')),
                'yearly_label'  => SuperPresenter::money($p->getPriceForCycle('yearly')),
            ])->values(),
        ]);
    }

    // ── Workspace users (same actions as the web Users panel) ─────────

    public function blockUser(Request $request, Tenant $tenant, int $userId)
    {
        return $this->runWebAction($request, fn () => app(WebTenantUserController::class)->block($tenant, $userId));
    }

    public function unblockUser(Request $request, Tenant $tenant, int $userId)
    {
        return $this->runWebAction($request, fn () => app(WebTenantUserController::class)->unblock($tenant, $userId));
    }

    /** Expects password + password_confirmation (min 8), like the web form. */
    public function userPassword(Request $request, Tenant $tenant, int $userId)
    {
        return $this->runWebAction($request, fn () => app(WebTenantUserController::class)->changePassword($request, $tenant, $userId));
    }

    public function logoutUser(Request $request, Tenant $tenant, int $userId)
    {
        return $this->runWebAction($request, fn () => app(WebTenantUserController::class)->forceLogoutUser($tenant, $userId));
    }

    /** DNS records the customer must add; any one of them is enough. */
    private function dnsFor($record): array
    {
        $dns = app(CustomDomainManager::class)->dnsInstructions($record);

        return [
            ['type' => 'CNAME', 'host' => $dns['cname']['host'], 'value' => $dns['cname']['value']],
            ['type' => 'A', 'host' => $dns['a']['host'], 'value' => implode(', ', (array) $dns['a']['value'])],
            ['type' => 'TXT', 'host' => $dns['txt']['host'], 'value' => $dns['txt']['value']],
        ];
    }

    /** Same meaning as the web list's status filter. */
    private function filtered($query, string $filter)
    {
        $now = now();

        return match ($filter) {
            'trial' => $query->whereIn('id', TenantSubscription::where('status', TenantSubscription::STATUS_TRIAL)
                ->where('trial_ends_at', '>', $now)->select('tenant_id')),
            'expiring' => $query->where('status', Tenant::STATUS_ACTIVE)
                ->whereIn('id', TenantSubscription::where(function ($q) use ($now) {
                    $soon = $now->copy()->addDays(7);
                    $q->where(fn ($p) => $p->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_CANCELLED])
                                           ->whereBetween('ends_at', [$now, $soon]))
                      ->orWhere(fn ($t) => $t->where('status', TenantSubscription::STATUS_TRIAL)
                                             ->whereBetween('trial_ends_at', [$now, $soon]));
                })->select('tenant_id')),
            'active', 'pending', 'suspended' => $query->where('status', $filter),
            default => $query,
        };
    }

    /**
     * Plan usage and the first users, read from the workspace's own database.
     * A workspace whose database is unreachable still opens, without them.
     *
     * @return array{0: array, 1: array, 2: int}
     */
    private function fromTenantDatabase(Tenant $tenant, ?Plan $plan): array
    {
        $usage = [];
        $users = [];
        $count = 0;

        try {
            $tenant->run(function () use ($plan, &$usage, &$users, &$count) {
                if ($plan) {
                    foreach (['max_users', 'max_warehouses', 'max_products', 'max_customers'] as $key) {
                        $limit = $plan->getLimit($key);
                        $usage[] = [
                            'key'   => $key,
                            'label' => Plan::AVAILABLE_LIMITS[$key]['label'],
                            'used'  => Plan::countUsage($key),
                            'limit' => $limit < 0 ? null : $limit,
                        ];
                    }
                }

                $count = User::count();
                $users = User::with('roles')->orderBy('id')->limit(20)->get()->map(fn ($u) => [
                    'id'     => $u->id,
                    'name'   => trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: $u->username,
                    'email'  => $u->email,
                    'role'   => optional($u->roles->first())->name,
                    'active' => (bool) $u->statut,
                ])->all();
            });
        } catch (\Throwable) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        return [$usage, $users, $count];
    }
}
