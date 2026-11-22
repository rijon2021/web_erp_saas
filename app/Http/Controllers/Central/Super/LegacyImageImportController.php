<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Services\LegacyProductImageImporter;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * /super/tools/legacy-images — attach old-POS images to a tenant's products.
 *
 * 1. Check: reads the CSV + image folder on the server, matches against the tenant's
 *    products, writes nothing to the tenant. The plan is saved as a JSON file
 *    (storage/app/legacy-images/<token>.json) together with a CSV report.
 * 2. Sync: the browser calls sync() repeatedly with an offset; each call imports one
 *    small batch so no single request runs into PHP/web-server timeouts.
 *
 * Files under storage/ are resolved BEFORE tenancy is initialised, because the
 * filesystem bootstrapper suffixes storage_path() per tenant.
 */
class LegacyImageImportController extends Controller
{
    private const BATCH = 20;

    public function __construct(private LegacyProductImageImporter $importer)
    {
    }

    public function index()
    {
        return view('central.super.tools.legacy-images');
    }

    public function check(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:191'],
            'csv_path' => ['required', 'string', 'max:1000'],
            'images_path' => ['required', 'string', 'max:1000'],
            'match' => ['required', 'in:code,name,code_name'],
            'overwrite' => ['nullable', 'boolean'],
            'resize' => ['nullable', 'boolean'],
        ]);

        $csv = trim($data['csv_path']);
        $dir = rtrim(trim($data['images_path']), '/\\');
        // Samples of the file are echoed back, so never read anything but a CSV.
        if (! in_array(strtolower(pathinfo($csv, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            return response()->json(['message' => 'The CSV path must point to a .csv file.'], 422);
        }
        if (! is_file($csv) || ! is_readable($csv)) {
            return response()->json(['message' => 'CSV file not found or not readable: '.$csv], 422);
        }
        if (! is_dir($dir) || ! is_readable($dir)) {
            return response()->json(['message' => 'Images folder not found or not readable: '.$dir], 422);
        }
        $tenant = Tenant::find(trim($data['tenant_id']));
        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found.'], 422);
        }

        @set_time_limit(300);
        $matchOrder = $data['match'] === 'code_name' ? ['code', 'name'] : [$data['match']];
        $overwrite = (bool) ($data['overwrite'] ?? false);
        $token = Str::random(24);
        $workDir = $this->workDir();

        $files = $this->importer->indexFolder($dir);
        $oldRows = $this->importer->readCsv($csv);

        tenancy()->initialize($tenant);
        try {
            $plan = $this->importer->plan($oldRows, $files, $matchOrder, $overwrite);
            $productsTotal = DB::table('products')->whereNull('deleted_at')->count();
            $withoutImage = $this->importer->countWithoutImage();
        } finally {
            tenancy()->end();
        }

        $items = [];
        foreach ($plan as $r) {
            if ($r['status'] === 'would_import') {
                $items[] = ['old_id' => $r['old_id'], 'product_id' => $r['product_id'], 'product_name' => $r['product_name'], 'sources' => $r['sources']];
            }
        }

        File::put($workDir.'/'.$token.'.json', json_encode([
            'tenant_id' => $tenant->id,
            'overwrite' => $overwrite,
            'resize' => (bool) ($data['resize'] ?? true),
            'items' => $items,
            'created_at' => now()->toDateTimeString(),
        ]));
        $this->importer->writeReport($workDir.'/'.$token.'-check.csv', $plan);

        $samples = [];
        foreach ($plan as $r) {
            if (in_array($r['status'], ['would_import', 'imported'], true) || count($samples[$r['status']] ?? []) >= 15) {
                continue;
            }
            $samples[$r['status']][] = array_intersect_key($r, array_flip(['old_id', 'old_code', 'old_name', 'images', 'product_id', 'product_name', 'detail']));
        }

        return response()->json([
            'token' => $token,
            'tenant' => $tenant->id,
            'old_rows' => count($oldRows),
            'image_files' => count($files),
            'products_total' => $productsTotal,
            'products_without_image' => $withoutImage,
            'stats' => $this->importer->stats($plan),
            'to_import' => count($items),
            'by_code' => count(array_filter($plan, fn ($r) => $r['status'] === 'would_import' && $r['matched_by'] === 'code')),
            'by_name' => count(array_filter($plan, fn ($r) => $r['status'] === 'would_import' && $r['matched_by'] === 'name')),
            'samples' => $samples,
            'report_url' => route('super.tools.legacy-images.report', ['token' => $token, 'type' => 'check']),
        ]);
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'alpha_num', 'size:24'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);

        $workDir = $this->workDir();
        $planFile = $workDir.'/'.$data['token'].'.json';
        if (! is_file($planFile)) {
            return response()->json(['message' => 'Check results expired or not found. Run Check again.'], 422);
        }
        $job = json_decode(File::get($planFile), true);
        $tenant = Tenant::find($job['tenant_id']);
        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found.'], 422);
        }

        @set_time_limit(300);
        $offset = (int) $data['offset'];
        $batch = array_slice($job['items'], $offset, self::BATCH);
        $resultFile = $workDir.'/'.$data['token'].'-sync.csv';
        $doneFile = $workDir.'/'.$data['token'].'-done.txt';

        // One sync per token at a time: a retried/timed-out request or a second tab
        // waits here instead of importing the same products in parallel.
        $lock = fopen($workDir.'/'.$data['token'].'.lock', 'c');
        flock($lock, LOCK_EX);

        // Products this token already handled (survives retries, even with "Replace images").
        $handled = is_file($doneFile)
            ? array_flip(array_filter(array_map('trim', file($doneFile))))
            : [];

        $isNew = ! is_file($resultFile);
        $out = fopen($resultFile, 'a');
        if ($isNew) {
            fputcsv($out, ['old_id', 'product_id', 'product_name', 'status', 'detail']);
        }
        $doneOut = fopen($doneFile, 'a');

        $counts = ['imported' => 0, 'skipped_has_image' => 0, 'already_done' => 0, 'error' => 0];
        $errors = [];

        tenancy()->initialize($tenant);
        try {
            foreach ($batch as $item) {
                $pid = (int) $item['product_id'];
                if (isset($handled[(string) $pid])) {
                    $counts['already_done']++;

                    continue;
                }
                $status = 'imported';
                $detail = '';
                try {
                    if (! $job['overwrite'] && $this->importer->productHasImage($pid)) {
                        $status = 'skipped_has_image';
                    } else {
                        $this->importer->attach($pid, $item['sources'], (bool) $job['resize']);
                    }
                } catch (\Throwable $e) {
                    $status = 'error';
                    $detail = $e->getMessage();
                    $errors[] = ['old_id' => $item['old_id'], 'product' => $item['product_name'], 'error' => $detail];
                }
                $counts[$status]++;
                fputcsv($out, [$item['old_id'], $pid, $item['product_name'], $status, $detail]);
                if ($status !== 'error') {
                    fwrite($doneOut, $pid."\n");
                    fflush($doneOut);
                }
            }
            $done = $offset + count($batch) >= count($job['items']);
            $withoutImage = $done ? $this->importer->countWithoutImage() : null;
        } finally {
            tenancy()->end();
            fclose($out);
            fclose($doneOut);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return response()->json([
            'counts' => $counts,
            'errors' => $errors,
            'next_offset' => $offset + count($batch),
            'total' => count($job['items']),
            'done' => $done,
            'products_without_image' => $withoutImage,
            'report_url' => route('super.tools.legacy-images.report', ['token' => $data['token'], 'type' => 'sync']),
        ]);
    }

    public function report(string $token, string $type)
    {
        $path = $this->workDir().'/'.$token.'-'.$type.'.csv';
        abort_unless(is_file($path), 404);

        return response()->download($path, 'legacy-images-'.$type.'-'.$token.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function workDir(): string
    {
        $dir = storage_path('app/legacy-images');
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
