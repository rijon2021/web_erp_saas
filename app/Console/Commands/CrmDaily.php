<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Models\CrmSetting;
use App\Services\Crm\CrmScoringService;
use App\Services\Crm\CrmStaleAlertService;
use App\Services\Crm\CrmWeeklyReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Daily CRM upkeep: re-score leads (time-based rules) and send the stale leads
 * / stuck deals digest — in every tenant whose plan includes the CRM.
 */
class CrmDaily extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'crm:daily {--force : Send the digest again even if it already went out today}';

    protected $description = 'Re-score CRM leads, send the stale leads / stuck deals digest and (Mondays) the weekly summary';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->forFeatureTenants('crm', function ($tenant) use ($force) {
            if (! Schema::hasTable('crm_scoring_rules')) {
                return;
            }
            // Fresh services per tenant: they cache settings and mail setup.
            $changed = CrmSetting::current()->scoring_enabled ? app(CrmScoringService::class)->refreshAll() : 0;
            $sent = app(CrmStaleAlertService::class)->run($force);
            // Mondays only (or with --force).
            $weeklySent = Schema::hasColumn('crm_settings', 'weekly_report') ? app(CrmWeeklyReportService::class)->run($force) : 0;
            $this->info("[{$tenant->id}] Lead scores changed: {$changed}; stale digests sent: {$sent}; weekly summaries sent: {$weeklySent}");
        });

        return self::SUCCESS;
    }
}
