<?php

namespace App\Console\Commands;

use App\Services\UpdateDiagnostics;
use Illuminate\Console\Command;

/**
 * Explain why /update fails on the central database and optionally repair it.
 *
 *   php artisan update:diagnose          # report only
 *   php artisan update:diagnose --fix    # mark orphaned tables as migrated
 *
 * The same report is available without shell access at GET /update/diagnose
 * (super admin login required).
 */
class UpdateDiagnose extends Command
{
    protected $signature = 'update:diagnose {--fix : Insert migrations rows for tables that exist but are not logged}';

    protected $description = 'Diagnose "table already exists" failures of the platform update and optionally repair them.';

    public function handle(UpdateDiagnostics $diag): int
    {
        $this->line($diag->toText());

        if ($this->option('fix')) {
            $marked = $diag->markOrphansAsMigrated();
            if (! $marked) {
                $this->info('Nothing to fix: no orphaned tables.');
            } else {
                $this->info('Marked as migrated:');
                foreach ($marked as $m) {
                    $this->line('  - ' . $m);
                }
                $this->info('Re-run /update now.');
            }
        }

        return 0;
    }
}
