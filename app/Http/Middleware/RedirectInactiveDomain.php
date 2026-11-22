<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantDomainStatus;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends browser visitors of a switched-off subdomain to the tenant's verified
 * custom domain (same path and query). Runs after tenancy is initialised, so
 * the address the request came in on is DomainTenantResolver::$currentDomain.
 *
 * Only page visits (GET/HEAD, not /api, not JSON) are redirected. Webhooks,
 * gateway callbacks and API clients that were set up with the subdomain keep
 * working, because a redirect would silently break them.
 */
class RedirectInactiveDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = DomainTenantResolver::$currentDomain;

        if (! $domain || TenantDomainStatus::isActive($domain) || ! $this->isPageVisit($request)) {
            return $next($request);
        }

        $target = TenantDomainStatus::customDomainTarget((string) $domain->tenant_id);

        // No reachable custom domain any more: serve the subdomain rather
        // than lock everyone out.
        if ($target === null || strcasecmp($target, $request->getHost()) === 0) {
            return $next($request);
        }

        // Keep a port only when the visitor typed one (dev hosts). getPort()
        // falls back to the server's own port, which behind a reverse proxy
        // is an internal port the public domain does not listen on.
        $portSuffix = '';
        if (preg_match('/:(\d+)$/', (string) $request->headers->get('host'), $m)
            && ! in_array((int) $m[1], [80, 443], true)) {
            $portSuffix = ':' . $m[1];
        }

        return redirect()->away($request->getScheme() . '://' . $target . $portSuffix . $request->getRequestUri(), 302);
    }

    private function isPageVisit(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && ! $request->is('api', 'api/*')
            && ! $request->expectsJson();
    }
}
