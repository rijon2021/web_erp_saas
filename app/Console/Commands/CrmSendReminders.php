<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Services\Crm\CrmReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Sends due CRM task reminders (email). Scheduled every five minutes, per CRM tenant. */
class CrmSendReminders extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'crm:send-reminders';

    protected $description = 'Send reminders for CRM tasks and planned activities that are coming due';

    public function handle(): int
    {
        $this->forFeatureTenants('crm', function ($tenant) {
            if (! Schema::hasTable('crm_activities')) {
                return;
            }
            $sent = app(CrmReminderService::class)->run();
            if ($sent) {
                $this->info("[{$tenant->id}] CRM reminders sent: {$sent}");
            }
        });

        return self::SUCCESS;
    }
}
