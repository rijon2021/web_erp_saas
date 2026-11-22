<?php

namespace App\Console\Commands;

use App\Support\Security\SecurityOptions;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency switch for Settings > Security: turns the login CAPTCHA and the
 * failed-attempt lockout off for the super admin login or for one tenant.
 * Turning the lockout off lifts every active block immediately (blocks are
 * only consulted while it is on).
 */
class LoginSecurityReset extends Command
{
    protected $signature = 'security:login-reset
                            {target : "central" for the super admin login, or a tenant id}';

    protected $description = 'Switch off login CAPTCHA and lockout for the super admin or a tenant';

    public function handle(): int
    {
        $target = (string) $this->argument('target');
        $off = ['captcha_enabled' => false, 'lockout_enabled' => false];

        if ($target === 'central') {
            if (! Schema::connection('central')->hasColumn('general_settings', 'security_options')) {
                $this->info('Login security is not set up (migration not run), so nothing is enabled.');

                return self::SUCCESS;
            }
            SecurityOptions::save(SecurityOptions::CENTRAL, $off);
            $this->info('CAPTCHA and lockout are now off for the super admin login.');

            return self::SUCCESS;
        }

        $tenant = Tenant::find($target);
        if (! $tenant) {
            $this->error("No tenant with id \"{$target}\".");

            return self::FAILURE;
        }

        try {
            $migrated = $tenant->run(function () use ($off) {
                if (! Schema::hasColumn('settings', 'security_options')) {
                    return false;
                }
                SecurityOptions::save(SecurityOptions::TENANT, $off);

                return true;
            });
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        $this->info($migrated
            ? "CAPTCHA and lockout are now off for tenant \"{$target}\"."
            : "Login security is not set up for tenant \"{$target}\" (migration not run), so nothing is enabled.");

        return self::SUCCESS;
    }
}
