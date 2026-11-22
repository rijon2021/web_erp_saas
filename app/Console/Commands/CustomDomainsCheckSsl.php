<?php

namespace App\Console\Commands;

use App\Models\Central\CustomDomain;
use App\Services\CustomDomainManager;
use Illuminate\Console\Command;

/**
 * Re-checks HTTPS on every verified custom domain and updates its SSL badge
 * (active / failed). Scheduled hourly in Kernel, so the badge turns active
 * on its own once the certificate is installed on the server or proxy.
 *
 *   php artisan custom-domains:check-ssl                     all verified domains
 *   php artisan custom-domains:check-ssl --domain=shop.com   one domain
 */
class CustomDomainsCheckSsl extends Command
{
    protected $signature = 'custom-domains:check-ssl {--domain= : Check only this domain}';

    protected $description = 'Probe HTTPS on verified custom domains and update their SSL status';

    public function handle(CustomDomainManager $manager): int
    {
        $query = CustomDomain::query()->where('is_verified', true);

        if ($domain = $this->option('domain')) {
            $query->where('domain', $manager->normalize((string) $domain));
        }

        $checked = 0;
        $query->orderBy('id')->each(function (CustomDomain $record) use ($manager, &$checked) {
            $result = $manager->checkSsl($record);
            $checked++;

            $this->line(sprintf(
                '%-40s %s  %s',
                $record->domain,
                $result['ok'] ? 'active' : 'failed',
                $result['ok'] ? '' : $manager->sslReasonMessage($result)
            ));
        });

        $this->info("Checked {$checked} domain(s).");

        return self::SUCCESS;
    }
}
