<?php

namespace App\Console\Commands;

use App\Services\Billing\AddonService;
use Illuminate\Console\Command;

/**
 * Daily add-on housekeeping: expire add-ons past their end date, email a
 * renewal payment link 7 days before the end, and cancel purchases nobody paid.
 */
class AddonsCheckExpiry extends Command
{
    protected $signature = 'addons:check-expiry';

    protected $description = 'Expire lapsed add-ons, send renewal links, drop unpaid add-on purchases';

    public function handle(AddonService $service): int
    {
        $stats = $service->sweep();

        $this->info("Expired: {$stats['expired']}, renewal links sent: {$stats['reminded']}, abandoned purchases: {$stats['abandoned']}.");

        return self::SUCCESS;
    }
}
