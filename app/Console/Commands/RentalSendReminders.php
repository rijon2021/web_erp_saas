<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Services\Rental\RentalReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rental reminders (rent due, overdue, contract ending, return due) over
 * the channels switched on in Rental Settings → Reminders, per rental tenant.
 */
class RentalSendReminders extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'rental:send-reminders';

    protected $description = 'Send rental customer reminders (rent due, overdue, contract ending, return due)';

    public function handle(): int
    {
        $this->forFeatureTenants('rental', function ($tenant) {
            if (! Schema::hasTable('rental_reminders')) {
                return;
            }
            $sent = app(RentalReminderService::class)->run();
            $this->info("[{$tenant->id}] {$sent} rental reminder(s) sent.");
        });

        return self::SUCCESS;
    }
}
