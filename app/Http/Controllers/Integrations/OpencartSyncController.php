<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\OpencartLog;
use App\Models\OpencartSetting;
use App\Models\Warehouse;
use App\Services\Opencart\Client;
use App\Services\Opencart\OrderSync;
use App\Services\Opencart\SyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin API for the OpenCart integration: settings, the connector download,
 * and the cursor-batched sync endpoints driven by the frontend loop. Gated by
 * the `opencart_settings` permission; routes by `tenant.feature:opencart`.
 * The connector secret is write-only.
 */
class OpencartSyncController extends Controller
{
    private const CONNECTOR_PATH = 'connectors/opencart/pos_connector';

    private const CONNECTOR3_PATH = 'connectors/opencart3/pos_connector';

    /** Stands for the tenant's company name in the connector sources. */
    private const COMPANY_PLACEHOLDER = '__COMPANY_NAME__';

    /** Stands for the connector's website link in install.json. */
    private const LINK_PLACEHOLDER = '__CONNECTOR_LINK__';

    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        $settings = OpencartSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'store_url' => $settings->store_url,
            'has_secret' => ! empty($settings->secret),
            'store_id' => (int) $settings->store_id,
            'warehouse_id' => $settings->warehouse_id,
            'code_source' => $settings->codeSource(),
            'connector_link' => $settings->connector_link,
            'auto_sync_products' => (bool) $settings->auto_sync_products,
            'auto_sync_stock' => (bool) $settings->auto_sync_stock,
            'auto_import_orders' => (bool) $settings->auto_import_orders,
            'commit_status_ids' => $settings->commit_status_ids ?? [],
            'paid_status_ids' => $settings->paid_status_ids ?? [],
            'cancel_status_ids' => $settings->cancel_status_ids ?? [],
            'company_name' => OpencartSetting::companyName(),
            'connector_version' => $settings->connector_version,
            'opencart_version' => $settings->opencart_version,
            'warehouses' => Warehouse::whereNull('deleted_at')->get(['id', 'name']),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
            'last_webhook_at' => optional($settings->last_webhook_at)->toIso8601String(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'store_url' => 'nullable|string|max:191|url',
            'secret' => 'nullable|string|max:191',
            'store_id' => 'nullable|integer|min:0',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'code_source' => 'nullable|in:'.implode(',', OpencartSetting::CODE_SOURCES),
            'connector_link' => 'nullable|string|max:191|url',
            'auto_sync_products' => 'boolean',
            'auto_sync_stock' => 'boolean',
            'auto_import_orders' => 'boolean',
            'commit_status_ids' => 'array',
            'commit_status_ids.*' => 'integer',
            'paid_status_ids' => 'array',
            'paid_status_ids.*' => 'integer',
            'cancel_status_ids' => 'array',
            'cancel_status_ids.*' => 'integer',
        ]);

        $settings = OpencartSetting::current();
        $settings->enabled = (bool) $data['enabled'];

        if ($request->exists('store_url')) {
            $url = $this->normalizeStoreUrl($data['store_url'] ?? null);
            if ($url && ($problem = Client::urlProblem($url))) {
                throw ValidationException::withMessages(['store_url' => $problem]);
            }
            $settings->store_url = $url;
        }

        // Write-only secret: only replace when the request carries a new value.
        if (! empty($data['secret'])) {
            $settings->secret = trim($data['secret']);
        }

        foreach (['store_id', 'warehouse_id'] as $key) {
            if ($request->exists($key)) {
                $settings->{$key} = $data[$key] ?: ($key === 'store_id' ? 0 : null);
            }
        }

        foreach (['auto_sync_products', 'auto_sync_stock', 'auto_import_orders'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings->{$key} = (bool) $data[$key];
            }
        }

        if (! empty($data['code_source'])) {
            $settings->code_source = $data['code_source'];
        }

        if ($request->exists('connector_link')) {
            $settings->connector_link = trim((string) ($data['connector_link'] ?? '')) ?: null;
        }

        foreach (['commit_status_ids', 'paid_status_ids', 'cancel_status_ids'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings->{$key} = array_values(array_map('intval', (array) $data[$key]));
            }
        }

        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    /** Also finishes connecting: the store learns where to send order notices. */
    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);

        $settings = OpencartSetting::current();
        if (! $settings->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the store URL and connector secret first.'], 422);
        }

        $service = SyncService::make($settings);

        try {
            return response()->json($service->testConnection($this->webhookUrl($settings)));
        } catch (\Throwable $e) {
            $service->log('connect.test', 'error', $e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * The extension, zipped from source so it always matches this release.
     * ?version=3 is the OpenCart 3.x build; anything else is the 4.x one.
     * The store shows the extension under the tenant's company name.
     */
    public function downloadConnector(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        $source = resource_path((int) $request->query('version') === 3 ? self::CONNECTOR3_PATH : self::CONNECTOR_PATH);
        abort_unless(is_dir($source) && class_exists(\ZipArchive::class), 404);

        $path = tempnam(sys_get_temp_dir(), 'occ');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $company = OpencartSetting::companyName();
        $link = OpencartSetting::current()->connectorLink();

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $zip->addFromString(str_replace(DIRECTORY_SEPARATOR, '/', $relative), $this->withCompanyName($file, $company, $link));
        }
        $zip->close();

        // OpenCart takes the extension's folder name from this file name.
        return response()->download($path, 'pos_connector.ocmod.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Connector text sits in PHP single-quoted strings, or in install.json. */
    private function withCompanyName(\SplFileInfo $file, string $company, string $link): string
    {
        $json = $file->getExtension() === 'json';
        $quote = fn (string $text) => $json
            ? substr((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1)
            : addcslashes($text, "'\\");

        return str_replace(
            [self::COMPANY_PLACEHOLDER, self::LINK_PLACEHOLDER],
            [$quote($company), $quote($link)],
            (string) file_get_contents($file->getPathname())
        );
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function resetMappings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);

        // Order links are never reset from here: without them the next pull
        // would import every order a second time and take its stock again.
        $data = $request->validate(['entity_type' => 'nullable|in:product,customer']);

        $deleted = SyncService::make()->resetMappings($data['entity_type'] ?? null);

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /** Bounds shared by the batched endpoints: one page of work per request. */
    private const BATCH_RULES = [
        'start_after_id' => 'nullable|integer|min:0',
        'batch' => 'nullable|integer|min:1|max:100',
        'page_info' => 'nullable|integer|min:1',
        'per_page' => 'nullable|integer|min:1|max:100',
    ];

    public function syncProducts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);
        $request->validate(self::BATCH_RULES + ['import_stock' => 'nullable|boolean']);
        $service = SyncService::make($this->configuredSettings());
        $this->prepareLongRequest();

        return $this->run(fn () => $request->query('mode', 'push') === 'pull'
            ? $service->pullProducts(
                (int) ($request->input('page_info') ?: 1),
                (int) ($request->input('per_page') ?: 50),
                $request->boolean('import_stock', true)
            )
            : $service->pushProducts(
                (int) $request->input('start_after_id', 0),
                (int) ($request->input('batch') ?: 15)
            ));
    }

    public function syncInventory(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);
        $request->validate(self::BATCH_RULES);
        $service = SyncService::make($this->configuredSettings());
        $this->prepareLongRequest();

        return $this->run(fn () => $service->pushInventory(
            (int) $request->input('start_after_id', 0),
            (int) ($request->input('batch') ?: 25)
        ));
    }

    public function syncOrders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);
        $request->validate(self::BATCH_RULES + [
            'since' => 'nullable|date',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id,deleted_at,NULL',
        ]);
        $service = OrderSync::make($this->configuredSettings());
        $this->prepareLongRequest();

        return $this->run(fn () => $service->pullOrders(
            (int) ($request->input('page_info') ?: 1),
            (int) ($request->input('per_page') ?: 25),
            $request->input('since') ?: null,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null
        ));
    }

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        return response()->json($this->logsQuery($request)->paginate(min(100, max(1, (int) ($request->input('per_page') ?: 20)))));
    }

    /** Counts and latest entries for the tiles on the logs page. */
    public function logsSummary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        $byLevel = fn ($query) => $query->selectRaw('level, COUNT(*) AS n')->groupBy('level')->pluck('n', 'level');
        $levels = $byLevel(OpencartLog::query());
        $recent = $byLevel(OpencartLog::query()->where('created_at', '>=', now()->subDay()));
        $actions = OpencartLog::query()
            ->selectRaw('action, COUNT(*) AS n, MAX(id) AS last_id')
            ->groupBy('action')
            ->orderByDesc('last_id')
            ->get();
        $settings = OpencartSetting::current();

        $counts = fn ($rows) => [
            'error' => (int) ($rows['error'] ?? 0),
            'warning' => (int) ($rows['warning'] ?? 0),
            'info' => (int) ($rows['info'] ?? 0),
        ];

        return response()->json([
            'total' => (int) $levels->sum(),
            'levels' => $counts($levels),
            'last_24h' => $counts($recent),
            'actions' => $actions->map(fn ($row) => ['action' => (string) $row->action, 'count' => (int) $row->n])->values(),
            'last_error' => OpencartLog::where('level', 'error')->orderByDesc('id')->first(),
            'last_pull' => OpencartLog::where('action', 'products.pull')->orderByDesc('id')->first(),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
            'last_webhook_at' => optional($settings->last_webhook_at)->toIso8601String(),
            'connector_version' => $settings->connector_version,
        ]);
    }

    /** The filtered list as a CSV file, newest first, for sending to support. */
    public function exportLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', OpencartSetting::class);

        $rows = $this->logsQuery($request)->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents
            fputcsv($out, ['Date', 'Level', 'Action', 'Message', 'Details']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    optional($row->created_at)->format('Y-m-d H:i:s'),
                    $row->level,
                    $row->action,
                    $row->message,
                    $row->context ? json_encode($row->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                ]);
            }
            fclose($out);
        }, 'opencart-logs-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Filters shared by the list and the export: level, action, text, date range. */
    private function logsQuery(Request $request)
    {
        $data = $request->validate([
            'level' => 'nullable|in:info,warning,error',
            'action' => 'nullable|string|max:100',
            'q' => 'nullable|string|max:191',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = OpencartLog::query()->orderByDesc('id');
        if (! empty($data['level'])) {
            $query->where('level', $data['level']);
        }
        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }
        if (trim((string) ($data['q'] ?? '')) !== '') {
            $q = trim((string) $data['q']);
            $query->where(fn ($w) => $w->where('message', 'LIKE', "%{$q}%")->orWhere('context', 'LIKE', "%{$q}%"));
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($data['from'])->startOfDay());
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($data['to'])->endOfDay());
        }

        return $query;
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', OpencartSetting::class);

        OpencartLog::query()->delete();

        return response()->json(['success' => true]);
    }

    /** The sync methods report store failures as exceptions; the page wants JSON. */
    private function run(callable $sync)
    {
        try {
            return response()->json($sync());
        } catch (\Throwable $e) {
            SyncService::make()->log('sync', 'error', $e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function configuredSettings(): OpencartSetting
    {
        $settings = OpencartSetting::current();
        abort_if(! $settings->isConfigured(), 422, 'OpenCart is not configured. Add the store URL and connector secret first.');

        return $settings;
    }

    /** People paste the address they see, index.php and all. */
    private function normalizeStoreUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = (string) preg_replace('#/index\.php.*$#i', '', trim($url));

        return rtrim($url, '/');
    }

    private function webhookUrl(OpencartSetting $settings): string
    {
        return url('/api/opencart/webhook/'.$settings->webhookToken());
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }
}
