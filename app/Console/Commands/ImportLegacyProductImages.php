<?php

namespace App\Console\Commands;

use App\Services\LegacyProductImageImporter;
use App\Tenant;
use Illuminate\Console\Command;

/**
 * Attach old-POS product images to a tenant's products (see LegacyProductImageImporter).
 * Same logic as the /super/tools/legacy-images page. Run with --dry-run first.
 * Products that already have an image are skipped unless --overwrite, so re-runs are safe.
 */
class ImportLegacyProductImages extends Command
{
    protected $signature = 'products:import-legacy-images
        {tenant : Tenant ID}
        {--csv= : Path to the CSV exported from the old POS}
        {--images= : Folder that holds the old image files}
        {--match=code,name : Match order: "code,name", "code" or "name"}
        {--overwrite : Replace images on products that already have one}
        {--no-resize : Copy files as-is instead of applying the Resize Product Images setting}
        {--dry-run : Report only, write nothing}
        {--report= : Where to write the CSV report (default: storage/logs/legacy-images-<tenant>-<time>.csv)}';

    protected $description = 'Attach old-POS product images to a tenant\'s products by code/name';

    public function handle(LegacyProductImageImporter $importer): int
    {
        $csv = (string) $this->option('csv');
        $dir = rtrim((string) $this->option('images'), '/\\');
        if ($csv === '' || ! is_file($csv)) {
            $this->error('--csv file not found.');

            return self::FAILURE;
        }
        if ($dir === '' || ! is_dir($dir)) {
            $this->error('--images folder not found.');

            return self::FAILURE;
        }

        $matchOrder = array_values(array_intersect(
            array_map('trim', explode(',', strtolower((string) $this->option('match')))),
            ['code', 'name']
        ));
        if ($matchOrder === []) {
            $this->error('--match must be code, name or code,name.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $report = $this->option('report')
            ?: storage_path('logs/legacy-images-'.$tenant->id.'-'.now()->format('Ymd-His').($dry ? '-dry' : '').'.csv');

        $files = $importer->indexFolder($dir);
        $oldRows = $importer->readCsv($csv);
        $this->info(count($oldRows).' old rows, '.count($files).' image files.');

        tenancy()->initialize($tenant);
        try {
            $plan = $importer->plan($oldRows, $files, $matchOrder, (bool) $this->option('overwrite'));

            if (! $dry) {
                $resize = ! $this->option('no-resize');
                $bar = $this->output->createProgressBar(count(array_filter($plan, fn ($r) => $r['status'] === 'would_import')));
                foreach ($plan as &$row) {
                    if ($row['status'] !== 'would_import') {
                        continue;
                    }
                    try {
                        $importer->attach((int) $row['product_id'], $row['sources'], $resize);
                        $row['status'] = 'imported';
                    } catch (\Throwable $e) {
                        [$row['status'], $row['detail']] = ['error', $e->getMessage()];
                    }
                    $bar->advance();
                }
                unset($row);
                $bar->finish();
                $this->newLine(2);
            }

            $importer->writeReport($report, $plan);
            $stats = $importer->stats($plan);
            $this->table(['status', 'rows'], collect($stats)->map(fn ($c, $s) => [$s, $c])->values()->all());
            $without = $importer->countWithoutImage();
            if ($dry) {
                // Only imports onto products that had no image reduce the count.
                $without -= count(array_filter($plan, fn ($r) => $r['status'] === 'would_import' && ! $r['had_image']));
            }
            $this->line('Products without an image '.($dry ? 'after a real run' : 'now').': '.max(0, $without));
        } finally {
            tenancy()->end();
        }

        $this->info('Report: '.$report);
        if ($dry) {
            $this->warn('Dry run - nothing was written.');
        }

        return self::SUCCESS;
    }
}
