<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Central\CustomDomain;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * On/off state of a tenant's addresses (rows in Stancl's `domains` table).
 *
 * A subdomain may be switched off only while the tenant has a verified custom
 * domain that is itself on, so a tenant is never left unreachable. Switched-off
 * rows stay in the table: the name remains reserved for the tenant and can be
 * switched back on at any time. Rows mirrored from `custom_domains` are managed
 * by CustomDomainManager and are never toggled here.
 *
 * Before the 2026_11_09_000001 migration runs, every address counts as on.
 */
class TenantDomainStatus
{
    private static ?bool $supported = null;

    public static function supported(): bool
    {
        if (self::$supported === null) {
            try {
                self::$supported = Schema::connection('central')->hasColumn('domains', 'is_active');
            } catch (\Throwable) {
                return false; // don't memoise a transient failure
            }
        }

        return self::$supported;
    }

    public static function isActive(Domain $domain): bool
    {
        $value = $domain->getAttribute('is_active');

        return $value === null || (bool) $value;
    }

    /** Is this row the mirror of one of the tenant's custom domains? */
    public static function isCustomDomainRow(Domain $domain): bool
    {
        return CustomDomain::where('tenant_id', $domain->tenant_id)
            ->where('domain', $domain->domain)
            ->exists();
    }

    /**
     * The tenant's own domain that visitors of a switched-off subdomain should
     * land on, or null when there is none that is reachable:
     *   1. a verified custom domain (Custom Domains section), primary first;
     *   2. otherwise a full domain the super admin added straight to the
     *      Domains list (e.g. "app.customer.com"), oldest first.
     * Only addresses that are switched on count. $exceptDomainId leaves out
     * the row about to be switched off or removed.
     */
    public static function customDomainTarget(string $tenantId, ?int $exceptDomainId = null): ?string
    {
        $rows = Domain::where('tenant_id', $tenantId)
            ->when($exceptDomainId, fn ($q) => $q->where('id', '!=', $exceptDomainId))
            ->orderBy('id')
            ->get()
            ->filter(fn (Domain $d) => self::isActive($d));

        if ($rows->isEmpty()) {
            return null;
        }

        $hosts = $rows->pluck('domain')->all();

        $verified = CustomDomain::where('tenant_id', $tenantId)
            ->where('is_verified', true)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->pluck('domain');

        foreach ($verified as $domain) {
            if (in_array($domain, $hosts, true)) {
                return $domain;
            }
        }

        $customHosts = CustomDomain::where('tenant_id', $tenantId)->pluck('domain')->all();
        $manager = app(\App\Services\CustomDomainManager::class);

        foreach ($hosts as $domain) {
            if (str_contains($domain, '.')
                && ! in_array($domain, $customHosts, true)
                && ! $manager->isCentralOrSubdomainOfCentral($domain)) {
                return $domain;
            }
        }

        return null;
    }

    public static function canDeactivate(string $tenantId, ?int $domainId = null): bool
    {
        return self::customDomainTarget($tenantId, $domainId) !== null;
    }

    /**
     * Switch the tenant's addresses back on when nothing reachable is left —
     * called after a custom domain is removed, so switched-off subdomains
     * never strand the tenant.
     */
    public static function reactivateIfStranded(string $tenantId): void
    {
        if (! self::supported() || self::customDomainTarget($tenantId) !== null) {
            return;
        }

        Domain::where('tenant_id', $tenantId)
            ->where('is_active', false)
            ->update(['is_active' => true]);
    }
}
