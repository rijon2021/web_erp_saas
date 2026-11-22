<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Services\AccountingV2\AssetJournal;
use Illuminate\Console\Command;

/**
 * Keeps fixed assets in the journal: posts each month's depreciation as it
 * accrues and catches up any asset whose purchase / disposal is not on the
 * books yet (e.g. assets created before asset postings existed).
 */
class AssetsPostDepreciation extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'assets:post-depreciation';

    protected $description = 'Post fixed-asset purchase, depreciation and disposal journals for every tenant with the Assets module';

    public function handle(): int
    {
        $this->forFeatureTenants('assets', function ($tenant) {
            $n = AssetJournal::syncAll();
            $this->line("{$tenant->id}: {$n} asset(s) checked");
        });

        return self::SUCCESS;
    }
}
