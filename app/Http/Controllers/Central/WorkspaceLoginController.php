<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Workspace sign-in on the main domain. Tenant users log in on their own
 * subdomain, so this page only asks for the workspace address and forwards
 * the visitor to that workspace's login page. It is what the main domain
 * shows when the landing page is disabled with the "Login page" target.
 */
class WorkspaceLoginController extends Controller
{
    public function show(): View
    {
        return view('central.workspace-login', [
            'baseDomain' => $this->baseDomain(),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:255'],
        ]);

        $tenant = $this->findTenant($validated['workspace']);
        $loginUrl = $tenant?->getLoginUrl() ?? '';

        if ($loginUrl === '') {
            return back()->withInput()->withErrors([
                'workspace' => __('landing.workspace_not_found'),
            ]);
        }

        return redirect()->away($loginUrl);
    }

    /**
     * Accepts "acme", "acme.example.com", a custom domain, or a pasted URL.
     */
    protected function findTenant(string $input): ?Tenant
    {
        $input = strtolower(trim($input));
        $host = parse_url(str_contains($input, '://') ? $input : "http://{$input}", PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', (string) $host);

        if ($host === '') {
            return null;
        }

        $candidates = [$host];
        $suffix = '.' . $this->baseDomain();
        if (str_ends_with($host, $suffix)) {
            $candidates[] = substr($host, 0, -strlen($suffix));
        }

        $domain = Domain::whereIn('domain', $candidates)->first();

        return $domain ? Tenant::find($domain->tenant_id) : null;
    }

    /** Same base domain Tenant::getTenantUrl() appends to bare subdomains. */
    protected function baseDomain(): string
    {
        return preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost');
    }
}
