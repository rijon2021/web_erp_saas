<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Models\Booking;
use App\Services\Booking\BookingNotifier;
use App\Support\BookingModule;
use Illuminate\Console\Command;

/**
 * Reminders before confirmed website bookings (Booking Settings ›
 * Notifications › Reminder), per tenant on a plan with `bookings`. Each
 * booking is reminded once; a new time (reschedule) gets a new reminder.
 */
class BookingSendReminders extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'bookings:send-reminders';

    protected $description = 'Send reminders before confirmed website bookings';

    public function handle(): int
    {
        $this->forFeatureTenants('bookings', function ($tenant) {
            BookingModule::forget();
            BookingModule::useTenantUrl();
            if (! BookingModule::enabled() || ! Booking::notificationsReady()) {
                return;
            }
            $sent = app(BookingNotifier::class)->sendDueReminders();
            if ($sent) {
                $this->info("[{$tenant->id}] {$sent} booking reminder(s) sent.");
            }
        });

        return self::SUCCESS;
    }
}
