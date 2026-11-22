<?php

namespace App\Console\Commands;

use App\Services\Licensing\LicenseService;
use Illuminate\Console\Command;

/**
 * Daily UI Lib licence re-check (scheduled in Kernel; runs alone via
 * `php artisan license:check`).
 *
 * A successful answer stores a fresh signed token (trusted offline for the
 * next 7 days). The token is dropped only when ui-lib.com really says the
 * licence is gone (refunded, revoked, released on this domain); when the
 * server cannot be reached the stored token is kept and expires on its own.
 */
class LicenseCheck extends Command
{
    protected $signature = 'license:check';

    protected $description = 'Re-check the UI Lib licence for this install and refresh its signed token';

    public function handle(LicenseService $service): int
    {
        $state = $service->state();
        if (! ($state['available'] ?? false)) {
            $this->warn('licence table missing — run php artisan migrate');

            return self::SUCCESS;
        }
        if (! ($state['has_license'] ?? false)) {
            $this->line('no licence stored — nothing to check');

            return self::SUCCESS;
        }

        $result = $service->check();

        if ($result['ok']) {
            $this->line('licence ok — '.(($result['data']['status'] ?? '') === 'active' ? 'active on '.($result['data']['domain'] ?? '?') : 'not activated on this domain'));
        } elseif ($result['network_error']) {
            $this->warn('ui-lib.com unreachable ('.($result['code'] ?? 'network').') — stored token kept');
        } else {
            $this->error('licence check failed: '.($result['code'] ?? '?').' — '.($result['message'] ?? ''));
        }

        return self::SUCCESS;
    }
}
