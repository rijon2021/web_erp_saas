<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Models\Booking;
use App\Services\Booking\BookingDepositService;
use App\Support\BookingModule;
use Illuminate\Console\Command;

/**
 * Website bookings whose deposit was not paid in time (Booking Settings ›
 * Deposit), per tenant on a plan with `bookings`: a payment that went
 * through without the customer coming back is picked up from the gateway,
 * the rest are cancelled when "Cancel unpaid bookings" is on.
 */
class BookingExpireDeposits extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'bookings:expire-deposits';

    protected $description = 'Pick up late deposit payments and cancel website bookings whose deposit was not paid in time';

    public function handle(): int
    {
        $this->forFeatureTenants('bookings', function ($tenant) {
            BookingModule::forget();
            BookingModule::useTenantUrl();
            if (! BookingModule::enabled() || ! Booking::depositsReady()) {
                return;
            }
            $r = app(BookingDepositService::class)->expireUnpaid();
            if ($r['reconciled'] || $r['cancelled']) {
                $this->info("[{$tenant->id}] {$r['reconciled']} late deposit(s) found, {$r['cancelled']} unpaid booking(s) cancelled.");
            }
        });

        return self::SUCCESS;
    }
}
