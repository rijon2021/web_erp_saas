<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Support\TenantDomainStatus;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            // Accept bare subdomains (e.g. "myshop") or full domains (e.g. "shop.example.com")
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i'],
        ]);

        $domain = strtolower(trim($validated['domain']));

        // Check across ALL tenants, not just this one
        $existsGlobally = \Stancl\Tenancy\Database\Models\Domain::where('domain', $domain)->exists();
        if ($existsGlobally) {
            return back()->withErrors(['domain' => __('super.flash.subdomain_taken')]);
        }

        try {
            $tenant->createDomain($domain);
        } catch (\Throwable $e) {
            return back()->withErrors(['domain' => __('super.flash.subdomain_failed', ['error' => $e->getMessage()])]);
        }

        return back()->with('success', __('super.flash.subdomain_added'));
    }

    public function destroy(Tenant $tenant, int $domainId): RedirectResponse
    {
        $domain = $tenant->domains()->findOrFail($domainId);

        if ($tenant->domains()->count() <= 1) {
            return back()->withErrors(['domain' => __('super.flash.domain_last')]);
        }

        // Never remove the last address that is switched on.
        if (TenantDomainStatus::isActive($domain)) {
            $otherActive = $tenant->domains()->where('id', '!=', $domain->id)->get()
                ->contains(fn ($d) => TenantDomainStatus::isActive($d));
            if (! $otherActive) {
                return back()->withErrors(['domain' => __('super.flash.domain_last_active')]);
            }
        }

        $domain->delete();

        // Removing the domain that switched-off subdomains were sent to turns
        // them back on, so the tenant stays reachable.
        TenantDomainStatus::reactivateIfStranded((string) $tenant->id);

        return back()->with('success', __('super.flash.domain_removed'));
    }

    /**
     * Switch a subdomain off (visitors go to the tenant's custom domain) or
     * back on. The row is kept either way, so the name stays reserved.
     */
    public function toggle(Tenant $tenant, int $domainId): RedirectResponse
    {
        if (! TenantDomainStatus::supported()) {
            return back()->withErrors(['domain' => __('super.flash.domain_toggle_unavailable')]);
        }

        $domain = $tenant->domains()->findOrFail($domainId);

        if (TenantDomainStatus::isCustomDomainRow($domain)) {
            return back()->withErrors(['domain' => __('super.flash.domain_toggle_custom')]);
        }

        if (TenantDomainStatus::isActive($domain)) {
            if (! TenantDomainStatus::canDeactivate((string) $tenant->id, (int) $domain->id)) {
                return back()->withErrors(['domain' => __('super.flash.domain_off_needs_custom')]);
            }

            $domain->forceFill(['is_active' => false])->save();

            return back()->with('success', __('super.flash.domain_switched_off', ['domain' => $domain->domain]));
        }

        $domain->forceFill(['is_active' => true])->save();

        return back()->with('success', __('super.flash.domain_switched_on', ['domain' => $domain->domain]));
    }
}
