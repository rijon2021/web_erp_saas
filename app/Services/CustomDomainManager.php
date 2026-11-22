<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CustomDomainException;
use App\Models\Central\CustomDomain;
use App\Models\Central\SystemLog;
use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * One place for the custom-domain lifecycle, shared by the super-admin tenant
 * page and the tenant's own Settings > Custom Domain page:
 *
 *   add()         normalise + validate + (optional) plan limit, create pending row
 *   verify()      DNS check; on success mirror into Stancl's `domains` table so
 *                 InitializeTenancyByDomainOrSubdomain resolves the host
 *   checkSsl()    probe https://<domain> and store ssl_status (active/failed);
 *                 run after a successful verify(), hourly and on demand
 *   remove()      drop the mirror row and the record
 *   makePrimary() flag one verified domain as the tenant's preferred address
 *
 * Reason codes thrown via CustomDomainException / returned by verify():
 *   invalid, is_central, duplicate, limit_reached, not_verified,
 *   no_records, mismatch, lookup_failed, unknown
 */
class CustomDomainManager
{
    public const ERR_INVALID       = 'invalid';
    public const ERR_IS_CENTRAL    = 'is_central';
    public const ERR_DUPLICATE     = 'duplicate';
    public const ERR_LIMIT         = 'limit_reached';
    public const ERR_NOT_VERIFIED  = 'not_verified';

    /** RFC-1035-ish host name: labels of 1–63 chars, TLD alphabetic, ≤ 253 total. */
    public const DOMAIN_REGEX = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(
        private readonly CustomDomainVerifier $verifier,
        private readonly CustomDomainSslProbe $sslProbe,
    ) {
    }

    /* ------------------------------------------------------------ input */

    /**
     * Lower-case, strip scheme / path / port / trailing dot, so "HTTPS://Shop.Acme.com/"
     * and "shop.acme.com" are the same domain.
     */
    public function normalize(string $raw): string
    {
        $domain = strtolower(trim($raw));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain) ?? $domain;
        $domain = preg_replace('#[/?\#].*$#', '', $domain) ?? $domain;
        $domain = preg_replace('#:\d+$#', '', $domain) ?? $domain;

        return rtrim($domain, '.');
    }

    /**
     * Reason code when the (normalised) domain cannot be added, null when it can.
     * Does not check plan limits — see add().
     */
    public function validationError(string $domain): ?string
    {
        // Reserved first: "acme.stocky.test" is a platform address even if the
        // central host would not pass the strict regex (dev hosts with "_").
        if ($this->isCentralOrSubdomainOfCentral($domain)) {
            return self::ERR_IS_CENTRAL;
        }

        if ($domain === '' || ! preg_match(self::DOMAIN_REGEX, $domain)) {
            return self::ERR_INVALID;
        }

        if (Domain::where('domain', $domain)->exists()
            || CustomDomain::where('domain', $domain)->exists()) {
            return self::ERR_DUPLICATE;
        }

        return null;
    }

    /**
     * Central hosts themselves, and anything under them: those are the
     * platform's own subdomains and never a "custom" domain.
     */
    public function isCentralOrSubdomainOfCentral(string $domain): bool
    {
        foreach ($this->centralDomains() as $central) {
            if ($domain === $central || Str::endsWith($domain, '.' . $central)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------ lifecycle */

    /**
     * Create a pending domain for the tenant.
     *
     * @param  int|null  $limit  Maximum domains the tenant may own; null or a
     *                           negative value means unlimited (super admin,
     *                           or a plan with no quota set).
     *
     * @throws CustomDomainException
     */
    public function add(Tenant $tenant, string $raw, ?int $limit = null): CustomDomain
    {
        $domain = $this->normalize($raw);

        if ($reason = $this->validationError($domain)) {
            throw new CustomDomainException($reason);
        }

        if ($limit !== null && $limit >= 0 && $tenant->customDomains()->count() >= $limit) {
            throw new CustomDomainException(self::ERR_LIMIT);
        }

        return CustomDomain::create([
            'tenant_id'          => $tenant->id,
            'domain'             => $domain,
            'verification_token' => $this->verifier->generateToken(),
            'ssl_status'         => CustomDomain::SSL_PENDING,
        ]);
    }

    /**
     * Run the DNS check. On success the record is marked verified and mirrored
     * into the tenancy `domains` table; on failure the reason is stored on the
     * record and a DNS system-log entry is captured for the super admin.
     *
     * @return array{ok: bool, reason: string, records: array}
     */
    public function verify(CustomDomain $record): array
    {
        $result = $this->verifier->verify($record->domain, $record->verification_token);

        if ($result['ok']) {
            DB::connection('central')->transaction(function () use ($record) {
                $record->markVerified();

                if (! Domain::where('domain', $record->domain)->exists()) {
                    Domain::create([
                        'domain'    => $record->domain,
                        'tenant_id' => $record->tenant_id,
                    ]);
                }
            });

            // DNS is fine — now see whether HTTPS already works on it.
            $result['ssl'] = $this->checkSsl($record);

            return $result;
        }

        $record->markFailed($this->reasonMessage($result['reason']));

        try {
            SystemLog::capture([
                'tenant_id'       => $record->tenant_id,
                'type'            => SystemLog::TYPE_DNS,
                'severity'        => SystemLog::SEVERITY_WARNING,
                'message'         => 'Custom domain verification failed for ' . $record->domain . ': ' . $this->reasonMessage($result['reason']),
                'context'         => [
                    'domain'  => $record->domain,
                    'reason'  => $result['reason'],
                    'records' => $result['records'] ?? [],
                ],
                'suggested_cause' => $this->suggestedCause($result['reason']),
                'source'          => 'custom_domain_verify',
            ]);
        } catch (\Throwable) {
            // monitoring only — never fail the verify call over it
        }

        return $result;
    }

    /**
     * Probe https://<domain> and store the outcome as ssl_status (active /
     * failed). The platform does not issue certificates — this only reports
     * whether the server or proxy already serves a valid one. A failure is
     * logged under Logs & System Health once, when the status turns failed,
     * so the hourly sweep does not flood the log.
     *
     * @return array{ok: bool, reason: string, detail: string, names: string[], expires_in_days: int|null}
     */
    public function checkSsl(CustomDomain $record): array
    {
        $result   = $this->sslProbe->probe($record->domain);
        $previous = $record->ssl_status;

        $record->forceFill([
            'ssl_status' => $result['ok'] ? CustomDomain::SSL_ACTIVE : CustomDomain::SSL_FAILED,
        ])->save();

        if (! $result['ok'] && $previous !== CustomDomain::SSL_FAILED) {
            try {
                SystemLog::capture([
                    'tenant_id'       => $record->tenant_id,
                    'type'            => SystemLog::TYPE_SSL,
                    'severity'        => SystemLog::SEVERITY_WARNING,
                    'message'         => 'HTTPS is not working yet on custom domain ' . $record->domain . ': ' . $this->sslReasonMessage($result),
                    'context'         => [
                        'domain' => $record->domain,
                        'reason' => $result['reason'],
                        'detail' => $result['detail'],
                        'names'  => $result['names'],
                    ],
                    'suggested_cause' => $this->sslSuggestedCause($result['reason']),
                    'source'          => 'custom_domain_ssl',
                ]);
            } catch (\Throwable) {
                // monitoring only
            }
        }

        return $result;
    }

    /** English wording for a checkSsl() result (surfaces translate by reason code). */
    public function sslReasonMessage(array $result): string
    {
        return match ($result['reason'] ?? '') {
            CustomDomainSslProbe::OK            => 'HTTPS is working.',
            CustomDomainSslProbe::UNREACHABLE   => 'Nothing answers on HTTPS (port 443) for this domain.',
            CustomDomainSslProbe::NAME_MISMATCH => 'The server answers with a certificate for another domain (' . ($result['detail'] ?? '') . '). Install a certificate for this domain.',
            CustomDomainSslProbe::EXPIRED       => 'The certificate for this domain has expired.',
            CustomDomainSslProbe::UNTRUSTED     => 'The certificate is not trusted (self-signed or incomplete chain).',
            default                             => 'HTTPS check failed.',
        };
    }

    public function sslSuggestedCause(string $reason): string
    {
        return match ($reason) {
            CustomDomainSslProbe::UNREACHABLE   => 'The web server does not answer HTTPS for this host. Add the domain to the platform\'s virtual host (server_name / ServerAlias) and install a certificate for it, or proxy it through Cloudflare.',
            CustomDomainSslProbe::NAME_MISMATCH => 'The request reaches the server but gets the platform\'s own certificate. Issue a certificate for this domain (e.g. certbot --webroot on the platform\'s public folder) and serve it in a server block for this host, or proxy the domain through Cloudflare (SSL mode Full).',
            CustomDomainSslProbe::EXPIRED       => 'Renew the certificate (check the certbot timer / renewal hook).',
            CustomDomainSslProbe::UNTRUSTED     => 'Serve the full chain (fullchain.pem, not cert.pem) or replace the self-signed certificate.',
            default                             => 'See log context for details.',
        };
    }

    public function remove(CustomDomain $record): void
    {
        DB::connection('central')->transaction(function () use ($record) {
            // Only the mirrored row for this tenant — never a subdomain that
            // happens to share the name.
            Domain::where('domain', $record->domain)
                ->where('tenant_id', $record->tenant_id)
                ->delete();
            $record->delete();

            // Switched-off subdomains rely on a working custom domain; with
            // none left, switch them back on so the tenant stays reachable.
            \App\Support\TenantDomainStatus::reactivateIfStranded((string) $record->tenant_id);
        });
    }

    /** @throws CustomDomainException when the domain is not verified yet */
    public function makePrimary(CustomDomain $record): void
    {
        if (! $record->is_verified) {
            throw new CustomDomainException(self::ERR_NOT_VERIFIED);
        }

        DB::connection('central')->transaction(function () use ($record) {
            CustomDomain::where('tenant_id', $record->tenant_id)->update(['is_primary' => false]);
            $record->forceFill(['is_primary' => true])->save();
        });
    }

    /* ------------------------------------------------------------ display */

    public function centralHost(): string
    {
        return $this->verifier->centralHost();
    }

    /**
     * What the tenant has to create at their DNS provider for this domain.
     * Any ONE of the three records is enough for verification.
     */
    public function dnsInstructions(CustomDomain $record): array
    {
        $host = $this->centralHost();

        return [
            'cname' => ['host' => $record->domain, 'value' => $host],
            'a'     => ['host' => $record->domain, 'value' => $this->centralIps($host)],
            'txt'   => ['host' => '_saas-verify.' . $record->domain, 'value' => $record->verification_token],
        ];
    }

    /** English wording for a verify() / exception reason (surfaces translate by code). */
    public function reasonMessage(string $reason): string
    {
        return match ($reason) {
            self::ERR_INVALID                        => 'Enter a valid domain name such as shop.example.com.',
            self::ERR_IS_CENTRAL                     => 'This is a reserved platform domain.',
            self::ERR_DUPLICATE                      => 'This domain is already in use.',
            self::ERR_LIMIT                          => 'You have reached the number of custom domains allowed by your plan.',
            self::ERR_NOT_VERIFIED                   => 'Verify the domain before setting it as primary.',
            CustomDomainVerifier::RESULT_NO_RECORDS  => 'No DNS records found yet. It may take a few minutes to propagate.',
            CustomDomainVerifier::RESULT_MISMATCH    => 'DNS records found but they do not point to this server.',
            CustomDomainVerifier::RESULT_LOOKUP_FAIL => 'DNS lookup failed. Try again in a moment.',
            default                                  => 'Verification failed. Please check DNS settings.',
        };
    }

    public function suggestedCause(string $reason): string
    {
        return match ($reason) {
            CustomDomainVerifier::RESULT_MISMATCH    => 'The domain resolves, but not to this server. Update the CNAME/A record to point at the central host, or add the TXT verification record.',
            CustomDomainVerifier::RESULT_NO_RECORDS  => 'No DNS records found for this domain. Verify the domain is registered and DNS is configured.',
            CustomDomainVerifier::RESULT_LOOKUP_FAIL => 'DNS lookup failed. The resolver may be unreachable or the domain may be unregistered. Retry after DNS propagation (up to 48h).',
            default                                  => 'See log context for record details.',
        };
    }

    /* ------------------------------------------------------------ internals */

    /** @return string[] */
    private function centralDomains(): array
    {
        return array_values(array_filter(array_map(
            static fn ($d) => strtolower(trim((string) $d)),
            (array) config('tenancy.central_domains', [])
        )));
    }

    /** IPv4 addresses the central host resolves to (for the A-record hint); empty when unknown. */
    private function centralIps(string $host): array
    {
        if ($host === '') {
            return [];
        }

        try {
            $ips = @gethostbynamel($host);
        } catch (\Throwable) {
            $ips = false;
        }

        return is_array($ips) ? array_values(array_unique($ips)) : [];
    }
}
