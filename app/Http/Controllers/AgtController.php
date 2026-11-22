<?php

namespace App\Http\Controllers;

use App\Models\Central\EInvoicingProviderSetting;
use App\Models\AgtDocument;
use App\Models\AgtLog;
use App\Models\AgtSeries;
use App\Models\AgtSetting;
use App\Models\Central\AgtProducerSetting;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Services\Agt\AgtApiClient;
use App\Services\Agt\AgtService;
use App\Services\Agt\Jws;
use Illuminate\Http\Request;

/**
 * Settings > AGT E-Invoicing (Angola). The module switch itself lives in
 * System Settings > Features (settings.agt_einvoice_enabled).
 */
class AgtController extends Controller
{
    public function __construct(protected AgtService $service)
    {
    }

    /** GET agt/settings */
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $agt = AgtSetting::current();
        if (! $agt) {
            return response()->json(['message' => 'AGT tables are not migrated for this tenant.'], 409);
        }
        $setting = Setting::where('deleted_at', '=', null)->first();
        $producer = AgtProducerSetting::current();

        return response()->json([
            'settings' => $this->publicSettings($agt),
            'platform_enabled' => EInvoicingProviderSetting::platformEnabled('agt'),
            'module_enabled' => (bool) ($setting->agt_einvoice_enabled ?? false),
            'company' => ['name' => $setting->CompanyName ?? null],
            'producer' => [
                'configured' => $producer && ! $producer->missing($agt->environment),
                'missing' => $producer ? $producer->missing($agt->environment) : ['central_migration'],
                'product_id' => $producer->product_id ?? null,
                'product_version' => $producer->product_version ?? null,
                'software_validation_number' => $producer->software_validation_number ?? null,
            ],
            'environments' => collect(config('agt.environments'))
                ->map(fn ($env, $key) => ['value' => $key, 'label' => $env['label']])->values(),
            'document_types' => collect(config('agt.document_types'))
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $key.' — '.$label])->values(),
            'tax_codes' => collect(config('agt.tax_codes'))
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $key.' — '.$label])->values(),
        ]);
    }

    /** POST agt/settings — an empty private_key keeps the stored key. */
    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $agt = AgtSetting::current();
        if (! $agt) {
            return response()->json(['message' => 'AGT tables are not migrated for this tenant.'], 409);
        }

        $data = $request->validate([
            'auto_submit' => 'boolean',
            'environment' => 'in:test,production',
            'tax_registration_number' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9]+$/'],
            'company_name' => 'nullable|string|max:200',
            'establishment_number' => 'nullable|string|max:200',
            'eac_code' => ['nullable', 'string', 'size:5'],
            'private_key' => 'nullable|string|max:20000',
            'paid_sale_type' => 'in:FR,FT',
            'tax_code_map' => 'nullable|array',
            'tax_code_map.*' => 'in:NOR,INT,RED,OUT',
            'default_exemption_code' => ['nullable', 'string', 'size:3'],
            'default_unit' => 'nullable|string|max:20',
        ]);

        if (array_key_exists('private_key', $data)) {
            $pem = trim((string) $data['private_key']);
            if ($pem === '') {
                unset($data['private_key']);
            } else {
                try {
                    $bits = Jws::inspect($pem);
                } catch (\Throwable $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                if ($bits < 2048) {
                    return response()->json(['message' => 'AGT requires an RSA key of at least 2048 bits (this one has '.$bits.').'], 422);
                }
                $data['private_key'] = $pem;
            }
        }

        if (isset($data['tax_code_map'])) {
            $map = [];
            foreach ($data['tax_code_map'] as $rate => $code) {
                if (is_numeric($rate) && (float) $rate > 0) {
                    $map[rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.')] = $code;
                }
            }
            $data['tax_code_map'] = $map ?: AgtSetting::DEFAULT_TAX_CODE_MAP;
        }
        foreach (['default_exemption_code', 'eac_code'] as $upper) {
            if (! empty($data[$upper])) {
                $data[$upper] = strtoupper(trim($data[$upper]));
            }
        }
        if (array_key_exists('establishment_number', $data) && trim((string) $data['establishment_number']) === '') {
            $data['establishment_number'] = 'SEDE';
        }

        $agt->fill($data)->save();

        return response()->json(['success' => true, 'settings' => $this->publicSettings($agt->fresh())]);
    }

    /** POST agt/test-connection — signed listarSeries round-trip. */
    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $agt = AgtSetting::current();
        if (! $agt || ! $agt->isReady()) {
            return response()->json(['success' => false, 'message' => 'Save the taxpayer NIF and the AGT-issued private key first.'], 422);
        }

        try {
            $result = AgtApiClient::make($agt)->listSeries();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($result['error']) {
            return response()->json(['success' => false, 'message' => 'Could not reach AGT: '.$result['error']]);
        }
        if ($result['status'] === 401) {
            return response()->json(['success' => false, 'message' => 'AGT rejected the producer credentials (HTTP 401) — contact the platform administrator.']);
        }

        $json = is_array($result['json']) ? $result['json'] : [];

        return response()->json([
            'success' => (bool) $result['ok'],
            'message' => $result['ok']
                ? 'AGT reachable — credentials and signatures accepted. Series found: '.($json['seriesResultCount'] ?? 0)
                : 'AGT responded with HTTP '.$result['status'].': '.mb_substr((string) $result['body'], 0, 300),
        ]);
    }

    /** GET agt/series */
    public function series(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $agt = AgtSetting::current();

        return response()->json(
            AgtSeries::query()
                ->where('environment', $agt->environment ?? 'test')
                ->orderByDesc('series_year')->orderBy('document_type')->orderBy('id')
                ->get()
                ->map(fn (AgtSeries $s) => $s->toArray() + ['remaining' => $s->remaining()])
        );
    }

    /** POST agt/series/request — solicitarSerie. */
    public function requestSeries(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $data = $request->validate([
            'document_type' => 'required|in:'.implode(',', array_keys(config('agt.document_types'))),
            'series_year' => 'required|integer|min:2025|max:2100',
            'establishment_number' => 'nullable|string|max:200',
            'contingency' => 'nullable|in:N,C',
        ]);

        try {
            $series = $this->service->requestSeries(
                $data['document_type'],
                (int) $data['series_year'],
                $data['establishment_number'] ?? null,
                $data['contingency'] ?? 'N'
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'series' => $series]);
    }

    /** POST agt/series — register a series created on the AGT portal. */
    public function storeSeries(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $agt = AgtSetting::current();
        $data = $request->validate([
            'document_type' => 'required|in:'.implode(',', array_keys(config('agt.document_types'))),
            'series_code' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9]+$/'],
            'series_year' => 'required|integer|min:2025|max:2100',
            'establishment_number' => 'nullable|string|max:200',
            'contingency' => 'nullable|in:N,C',
            'first_number' => 'nullable|integer|min:1',
            'last_number' => 'nullable|integer|min:1',
            'next_number' => 'nullable|integer|min:1',
        ]);

        $exists = AgtSeries::query()->where('environment', $agt->environment)->where('series_code', $data['series_code'])->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'This series is already registered.'], 422);
        }

        return response()->json(['success' => true, 'series' => $this->service->storeSeries($agt, $data)]);
    }

    /** PUT agt/series/{id} — activate/deactivate or move the next number forward. */
    public function updateSeries(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $series = AgtSeries::query()->findOrFail((int) $id);
        $data = $request->validate([
            'active' => 'boolean',
            'next_number' => 'nullable|integer|min:1',
        ]);

        if (isset($data['next_number'])) {
            // Numbers already issued are never reused: the counter only moves forward.
            if ((int) $data['next_number'] < $series->next_number) {
                return response()->json(['success' => false, 'message' => 'The next number can only move forward (current: '.$series->next_number.').'], 422);
            }
            $series->next_number = (int) $data['next_number'];
        }
        if (array_key_exists('active', $data)) {
            $series->active = (bool) $data['active'];
        }
        $series->save();

        return response()->json(['success' => true, 'series' => $series]);
    }

    /** GET agt/documents */
    public function documents(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $query = AgtDocument::query()->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(fn ($q) => $q->where('document_no', 'like', $term)->orWhere('invoice_ref', 'like', $term));
        }

        $documents = $query->paginate((int) $request->get('limit', 20));
        $documents->getCollection()->transform(fn (AgtDocument $doc) => [
            'id' => $doc->id,
            'source' => $doc->source_type === SaleReturn::class ? 'return' : 'sale',
            'source_id' => $doc->source_id,
            'invoice_ref' => $doc->invoice_ref,
            'document_type' => $doc->document_type,
            'document_no' => $doc->document_no,
            'document_date' => optional($doc->document_date)->toDateString(),
            'gross_total' => $doc->gross_total,
            'environment' => $doc->environment,
            'status' => $doc->status,
            'request_id' => $doc->request_id,
            'rejected_document_no' => $doc->rejected_document_no,
            'errors' => $doc->errors,
            'attempts' => $doc->attempts,
            'submitted_at' => optional($doc->submitted_at)->toDateTimeString(),
            'validated_at' => optional($doc->validated_at)->toDateTimeString(),
            'late' => $doc->status !== 'valid' && $doc->system_entry_date
                && $doc->system_entry_date->lt(now()->subHours((int) config('agt.late_after_hours', 24))),
        ]);

        return response()->json($documents);
    }

    /** GET agt/documents/{id} — payload and last AGT response. */
    public function document(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $doc = AgtDocument::query()->findOrFail((int) $id);

        return response()->json([
            'document' => $doc->only(['id', 'document_no', 'document_type', 'status', 'request_id', 'errors', 'hash_extract', 'qr_payload']),
            'payload' => $doc->payload,
            'response' => $doc->response,
        ]);
    }

    /** POST agt/documents/{id}/retry — re-send, or correct a rejected document. */
    public function retry(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $doc = AgtDocument::query()->findOrFail((int) $id);

        try {
            $doc = $this->service->retry($doc);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json($this->outcome($doc));
    }

    /** POST agt/documents/{id}/check — obterEstado now. */
    public function check(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        $doc = AgtDocument::query()->findOrFail((int) $id);

        try {
            $doc = $this->service->check($doc);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json($this->outcome($doc));
    }

    /** POST agt/sales/{id}/submit */
    public function submitSale(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        return $this->submitSource(Sale::query()->whereNull('deleted_at')->findOrFail((int) $id));
    }

    /** POST agt/returns/{id}/submit */
    public function submitReturn(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        return $this->submitSource(SaleReturn::query()->whereNull('deleted_at')->findOrFail((int) $id));
    }

    /** GET agt/logs */
    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'agt_settings', Sale::class);

        return response()->json(
            AgtLog::query()->orderByDesc('id')->limit(min(200, (int) $request->get('limit', 50)))->get()
        );
    }

    protected function submitSource($source)
    {
        if (! $this->service->isFiscalDocument($source)) {
            return response()->json(['success' => false, 'message' => 'Only completed sales and received returns are communicated to AGT.'], 422);
        }

        try {
            $doc = $this->service->process($source);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json($this->outcome($doc));
    }

    protected function outcome(AgtDocument $doc): array
    {
        return [
            'success' => in_array($doc->status, ['submitted', 'valid'], true),
            'status' => $doc->status,
            'document_no' => $doc->document_no,
            'request_id' => $doc->request_id,
            'error' => $doc->errors,
        ];
    }

    protected function publicSettings(AgtSetting $agt): array
    {
        $publicKey = null;
        if (! empty($agt->private_key)) {
            try {
                $publicKey = Jws::publicKey((string) $agt->private_key);
            } catch (\Throwable $e) {
                $publicKey = null;
            }
        }

        return [
            'auto_submit' => (bool) $agt->auto_submit,
            'environment' => $agt->environment,
            'tax_registration_number' => $agt->tax_registration_number,
            'company_name' => $agt->company_name,
            'establishment_number' => $agt->establishment_number,
            'eac_code' => $agt->eac_code,
            'has_private_key' => ! empty($agt->private_key),
            'public_key' => $publicKey,
            'paid_sale_type' => $agt->paid_sale_type,
            'tax_code_map' => $agt->tax_code_map ?: AgtSetting::DEFAULT_TAX_CODE_MAP,
            'default_exemption_code' => $agt->default_exemption_code,
            'default_unit' => $agt->default_unit,
        ];
    }
}
