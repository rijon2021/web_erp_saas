<?php

namespace App\Http\Controllers;

use App\Exceptions\CustomDomainException;
use App\Models\Central\CustomDomain;
use App\Models\Sale;
use App\Services\CustomDomainManager;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;

/**
 * Settings > Custom Domain: the tenant adds, DNS-verifies and removes their
 * own domains (shop.acme.com) for this workspace.
 *
 * Gated by the `custom_domains` permission and the plan feature of the same
 * name; the plan limit `max_custom_domains` caps how many a tenant may own.
 * The lifecycle itself is CustomDomainManager (shared with the super admin);
 * error responses carry a stable `code` the SPA translates.
 */
class CustomDomainsController extends BaseController
{
    public function __construct(
        private readonly CustomDomainManager $domains,
        private readonly TenantLimitsService $limits,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'custom_domains', Sale::class);

        $tenant = tenant();
        $rows = $tenant->customDomains()->orderBy('id')->get();

        $central = $this->domains->centralHost();
        // The platform address: a bare subdomain ("acme") or a full host under a
        // central domain ("acme.stocky.test") — custom domains are neither.
        $subdomain = $tenant->domains()->get()->pluck('domain')
            ->map(fn ($d) => strtolower((string) $d))
            ->first(fn ($d) => ! str_contains($d, '.') || $this->domains->isCentralOrSubdomainOfCentral($d));
        if ($subdomain && ! str_contains($subdomain, '.')) {
            $subdomain .= '.' . $central;
        }

        return response()->json([
            'domains'      => $rows->map(fn (CustomDomain $d) => $this->present($d))->values(),
            'central_host' => $central,
            'subdomain'    => $subdomain ?: null,
            'limit'        => $this->limits->getMaxAllowed('max_custom_domains'), // null = unlimited
            'used'         => $rows->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'custom_domains', Sale::class);

        $data = $request->validate(['domain' => 'required|string|max:253']);

        try {
            $record = $this->domains->add(tenant(), $data['domain'], $this->planLimit());
        } catch (CustomDomainException $e) {
            return $this->fail($e->getReason());
        }

        return response()->json(['domain' => $this->present($record)], 201);
    }

    public function verify(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'custom_domains', Sale::class);

        $record = tenant()->customDomains()->findOrFail($id);
        $result = $this->domains->verify($record);

        if (! $result['ok']) {
            return $this->fail($result['reason'], ['records' => $result['records'] ?? []]);
        }

        return response()->json(['domain' => $this->present($record->fresh())]);
    }

    public function makePrimary(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'custom_domains', Sale::class);

        $record = tenant()->customDomains()->findOrFail($id);

        try {
            $this->domains->makePrimary($record);
        } catch (CustomDomainException $e) {
            return $this->fail($e->getReason());
        }

        return response()->json(['domain' => $this->present($record->fresh())]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'custom_domains', Sale::class);

        $record = tenant()->customDomains()->findOrFail($id);
        $this->domains->remove($record);

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------ helpers */

    /** Plan quota; null when the plan sets none (unlimited). */
    private function planLimit(): ?int
    {
        return $this->limits->getMaxAllowed('max_custom_domains');
    }

    private function present(CustomDomain $d): array
    {
        return [
            'id'               => $d->id,
            'domain'           => $d->domain,
            'is_verified'      => (bool) $d->is_verified,
            'is_primary'       => (bool) $d->is_primary,
            'ssl_status'       => $d->ssl_status,
            'verified_at'      => $d->verified_at,
            'last_check_at'    => $d->last_check_at,
            'last_check_error' => $d->last_check_error,
            'created_at'       => $d->created_at,
            'dns'              => $this->domains->dnsInstructions($d),
        ];
    }

    private function fail(string $code, array $extra = [])
    {
        $status = $code === CustomDomainManager::ERR_LIMIT ? 403 : 422;

        // 403 + status=limit_reached is the contract the SPA http lib turns into
        // the usual "upgrade your plan" prompt (same as tenant.limit routes).
        if ($status === 403) {
            $extra['status'] = 'limit_reached';
        }

        return response()->json(array_merge([
            'code'    => $code,
            'message' => $this->domains->reasonMessage($code),
        ], $extra), $status);
    }
}
