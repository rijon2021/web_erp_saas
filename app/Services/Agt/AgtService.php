<?php

namespace App\Services\Agt;

use App\Models\Central\EInvoicingProviderSetting;
use App\Models\AgtDocument;
use App\Models\AgtSeries;
use App\Models\AgtSetting;
use App\Models\Central\AgtProducerSetting;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\TenantLimitsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Angola AGT e-invoicing orchestration.
 *
 *   issue()    allocate the next number of the AGT-authorised series, build
 *              and sign the document (status pending) — numbers are never
 *              reused, so a document keeps its number for life
 *   register() POST registarFactura → submitted (requestID) | invalid | error
 *   check()    POST obterEstado → valid | invalid (or still submitted)
 *   retry()    error → register again; invalid → supersede and re-issue a
 *              correction (documentStatus C, new number, rejectedDocumentNo)
 */
class AgtService
{
    public const FEATURE = 'agt';

    /** Post-create hook (job): never throws, no-op unless everything is set up. */
    public function processQuietly(Model $source): ?AgtDocument
    {
        try {
            $settings = AgtSetting::current();
            if (! $settings || ! $settings->auto_submit || ! $settings->isReady() || ! $this->moduleActive() || ! EInvoicingProviderSetting::platformEnabled('agt')) {
                return null;
            }
            // Platform side not set up yet: stay quiet instead of logging on every sale.
            $producer = AgtProducerSetting::current();
            if (! $producer || $producer->missing($settings->environment)) {
                return null;
            }
            if (! $this->isFiscalDocument($source)) {
                return null;
            }

            return $this->process($source);
        } catch (\Throwable $e) {
            \Log::warning('AGT submission failed (non-blocking): '.$e->getMessage(), [
                'source' => get_class($source).'#'.($source->id ?? '?'),
            ]);

            return null;
        }
    }

    /** Issue (if needed) and register a sale / return. Throws on configuration problems. */
    public function process(Model $source): AgtDocument
    {
        $settings = $this->readySettings();

        $document = AgtDocument::forSource($source);
        if (! $document) {
            $document = $this->issue($source, $settings);
        } elseif (in_array($document->status, ['valid', 'submitted'], true)) {
            return $document;
        } elseif ($document->status === 'invalid') {
            $document = $this->issue($source, $settings, $document);
        }

        return $this->register($document, $settings);
    }

    public function retry(AgtDocument $document): AgtDocument
    {
        $settings = $this->readySettings();

        if ($document->status === 'invalid') {
            $source = $this->sourceOf($document);
            if (! $source) {
                throw new RuntimeException('The original sale / return no longer exists.');
            }
            $document = $this->issue($source, $settings, $document);
        } elseif (! in_array($document->status, ['pending', 'error'], true)) {
            return $document;
        }

        return $this->register($document, $settings);
    }

    /**
     * Allocate the next number and build the signed document. When
     * $rejected is given, it is superseded and the new document is sent as
     * its correction.
     */
    public function issue(Model $source, AgtSetting $settings, ?AgtDocument $rejected = null): AgtDocument
    {
        $client = AgtApiClient::make($settings);
        $type = $this->documentType($source, $settings);
        $documentDate = Carbon::parse($source->date)->toDateString();
        $year = (int) substr($documentDate, 0, 4);

        [$reference, $reason] = [null, null];
        if ($source instanceof SaleReturn) {
            $original = $source->sale_id ? Sale::query()->find($source->sale_id) : null;
            $originalDoc = $original ? AgtDocument::forSource($original) : null;
            if (! $originalDoc) {
                throw new RuntimeException('This return\'s sale was never communicated to AGT, so no credit note (NC) can reference it.');
            }
            $reference = $originalDoc->document_no;
            $reason = trim((string) ($source->notes ?? '')) ?: 'Devolução';
        }

        // Build before allocating: a mapping error must not burn a number.
        $built = (new AgtDocumentBuilder($settings))->build($source, $reference, $reason);

        return DB::transaction(function () use ($source, $settings, $client, $type, $documentDate, $year, $built, $rejected) {
            $series = $this->lockSeries($settings, $type, $year);

            // Re-check under the series lock: a second worker handling the
            // same sale must reuse the document instead of issuing another.
            $live = AgtDocument::forSource($source);
            if ($live && (! $rejected || $live->id !== $rejected->id)) {
                return $live;
            }
            $sequence = $series->next_number;
            $series->next_number = $sequence + 1;
            $series->save();

            $documentNo = $type.' '.$series->series_code.'/'.$sequence;
            $systemEntry = now();
            $nif = trim((string) $settings->tax_registration_number);

            $signed = [
                'documentNo' => $documentNo,
                'taxRegistrationNumber' => $nif,
                'documentType' => $type,
                'documentDate' => $documentDate,
                'customerTaxID' => $built['customer']['customerTaxID'],
                'customerCountry' => $built['customer']['customerCountry'],
                'companyName' => $built['customer']['companyName'],
                'documentTotals' => $built['totals'],
            ];
            $jws = $client->signDocument($signed);

            $payload = [
                'documentNo' => $documentNo,
                'documentStatus' => $rejected ? 'C' : 'N',
            ];
            if ($rejected) {
                $payload['rejectedDocumentNo'] = $rejected->document_no;
            }
            $payload += [
                'jwsDocumentSignature' => $jws,
                'documentDate' => $documentDate,
                'documentType' => $type,
            ];
            if (! empty($settings->eac_code)) {
                $payload['eacCode'] = (string) $settings->eac_code;
            }
            $payload += [
                'systemEntryDate' => $systemEntry->format('Y-m-d\TH:i:s'),
                'customerTaxID' => $built['customer']['customerTaxID'],
                'customerCountry' => $built['customer']['customerCountry'],
                'companyName' => $built['customer']['companyName'],
                'lines' => $built['lines'],
                'documentTotals' => $built['totals'],
            ];

            if ($rejected) {
                $rejected->update(['status' => 'superseded']);
            }

            $document = AgtDocument::create([
                'source_type' => get_class($source),
                'source_id' => $source->id,
                'invoice_ref' => $source->Ref ?? null,
                'environment' => $settings->environment,
                'document_type' => $type,
                'agt_series_id' => $series->id,
                'sequence' => $sequence,
                'document_no' => $documentNo,
                'document_date' => $documentDate,
                'system_entry_date' => $systemEntry,
                'rejected_document_no' => $rejected?->document_no,
                'net_total' => $built['totals']['netTotal'],
                'tax_payable' => $built['totals']['taxPayable'],
                'gross_total' => $built['totals']['grossTotal'],
                'jws_document_signature' => $jws,
                'hash_extract' => self::hashExtract($jws),
                'status' => 'pending',
                'payload' => $payload,
            ]);

            $document->qr_payload = $this->qrPayload($document, $nif, $built['customer']['customerTaxID']);
            $document->save();

            return $document;
        });
    }

    public function register(AgtDocument $document, ?AgtSetting $settings = null): AgtDocument
    {
        // Only documents AGT has not accepted for processing yet are (re)sent.
        if (! in_array($document->status, ['pending', 'error'], true)) {
            return $document;
        }
        $settings ??= $this->readySettings();
        $result = AgtApiClient::make($settings)->registerInvoices([$document->payload], $document->id);
        $json = is_array($result['json']) ? $result['json'] : [];
        $requestId = (string) ($json['requestID'] ?? '');
        $errors = $this->errorText($json['errorList'] ?? []);

        $document->attempts = $document->attempts + 1;
        $document->response = $json ?: null;
        $document->submitted_at = now();

        if ($result['ok'] && $requestId !== '') {
            $document->fill(['status' => 'submitted', 'request_id' => $requestId, 'errors' => null]);
        } elseif ($requestId !== '' && $this->hasError($json['errorList'] ?? [], 'E09')) {
            // Duplicate: AGT already holds this document — follow the first request.
            $document->fill(['status' => 'submitted', 'request_id' => $requestId, 'errors' => null]);
        } elseif ($result['status'] === 400 && $errors !== '') {
            $document->fill(['status' => 'invalid', 'errors' => $errors]);
        } else {
            $document->fill([
                'status' => 'error',
                'errors' => $result['error']
                    ?: ($result['status'] === 401 ? 'AGT rejected the producer credentials (HTTP 401).' : ($errors ?: 'AGT responded with HTTP '.($result['status'] ?? '?').'.')),
            ]);
        }
        $document->save();

        return $document;
    }

    /** obterEstado: move a submitted document to valid / invalid. */
    public function check(AgtDocument $document, ?AgtSetting $settings = null): AgtDocument
    {
        if ($document->status !== 'submitted' || empty($document->request_id)) {
            return $document;
        }
        $settings ??= $this->readySettings();
        $result = AgtApiClient::make($settings)->requestStatus($document->request_id, $document->id);
        $json = is_array($result['json']) ? $result['json'] : [];
        $document->checked_at = now();

        if ($result['ok'] && array_key_exists('resultCode', $json)) {
            $code = (int) $json['resultCode'];
            if (in_array($code, [0, 1, 2], true)) {
                $entry = collect($json['documentStatusList'] ?? [])->firstWhere('documentNo', $document->document_no);
                $state = strtoupper((string) ($entry['documentStatus'] ?? ($code === 0 ? 'V' : 'I')));
                if ($state === 'V') {
                    $document->fill(['status' => 'valid', 'validated_at' => now(), 'errors' => null, 'validation_status' => 'V']);
                } else {
                    $document->fill(['status' => 'invalid', 'errors' => $this->errorText($entry['errorList'] ?? $json['requestErrorList'] ?? []) ?: 'Rejected by AGT.']);
                }
                $document->response = $json;
            } elseif ($code === 9) {
                // Cancelled on the AGT side: register again.
                $document->fill(['status' => 'error', 'errors' => config('agt.result_codes.9')]);
            }
        }
        $document->save();

        return $document;
    }

    /**
     * Scheduler pass for one tenant: check submitted documents, re-send
     * pending / errored ones. Returns counters for the command output.
     */
    public function poll(int $limit = 50): array
    {
        $counts = ['checked' => 0, 'sent' => 0, 'valid' => 0, 'invalid' => 0];
        $settings = AgtSetting::current();
        if (! $settings || ! $settings->isReady() || ! $this->moduleActive()) {
            return $counts;
        }

        $submitted = AgtDocument::query()
            ->where('status', 'submitted')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(2)))
            ->orderBy('id')->limit($limit)->get();
        foreach ($submitted as $document) {
            $this->check($document, $settings);
            $counts['checked']++;
            $counts['valid'] += $document->status === 'valid' ? 1 : 0;
            $counts['invalid'] += $document->status === 'invalid' ? 1 : 0;
        }

        $resend = AgtDocument::query()
            ->whereIn('status', ['pending', 'error'])
            ->where('attempts', '<', 10)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('id')->limit($limit)->get();
        foreach ($resend as $document) {
            $this->register($document, $settings);
            $counts['sent']++;
        }

        return $counts;
    }

    /** solicitarSerie → store the authorised range locally. */
    public function requestSeries(string $type, int $year, ?string $establishment = null, string $contingency = 'N'): AgtSeries
    {
        $settings = $this->readySettings();
        $establishment = $establishment ?: ($settings->establishment_number ?: 'SEDE');
        $result = AgtApiClient::make($settings)->requestSeries($type, $year, $establishment, $contingency);
        $json = is_array($result['json']) ? $result['json'] : [];
        $info = $json['seriesFEResult'] ?? null;

        if (! $result['ok'] || ! is_array($info) || empty($info['seriesCode'])) {
            $message = $this->errorText($json['errorList'] ?? []) ?: ($result['error'] ?: 'AGT responded with HTTP '.($result['status'] ?? '?').'.');
            throw new RuntimeException('AGT did not create the series: '.$message);
        }

        return $this->storeSeries($settings, [
            'document_type' => $type,
            'series_code' => (string) $info['seriesCode'],
            'series_year' => $year,
            'establishment_number' => $establishment,
            'contingency' => $contingency,
            'first_number' => (int) ($info['firstDocumentNo'] ?? 1) ?: 1,
            'last_number' => (int) ($info['lastDocumentNo'] ?? 999999999999) ?: 999999999999,
        ]);
    }

    /** Add a series created elsewhere (e.g. directly on the AGT portal). */
    public function storeSeries(AgtSetting $settings, array $data): AgtSeries
    {
        $series = AgtSeries::query()->firstOrNew([
            'environment' => $settings->environment,
            'series_code' => $data['series_code'],
        ]);
        $first = (int) ($data['first_number'] ?? 1) ?: 1;
        $series->fill([
            'document_type' => $data['document_type'],
            'series_year' => (int) $data['series_year'],
            'establishment_number' => $data['establishment_number'] ?? ($settings->establishment_number ?: 'SEDE'),
            'contingency' => $data['contingency'] ?? 'N',
            'first_number' => $first,
            'last_number' => (int) ($data['last_number'] ?? 999999999999) ?: 999999999999,
            'active' => $data['active'] ?? true,
        ]);
        if (! $series->exists) {
            $series->next_number = max($first, (int) ($data['next_number'] ?? $first));
        }
        $series->save();

        return $series;
    }

    /** Fiscal type for a source: NC for returns, FR (or configured) when paid at creation, else FT. */
    public function documentType(Model $source, AgtSetting $settings): string
    {
        if ($source instanceof SaleReturn) {
            return 'NC';
        }
        $paid = (float) ($source->paid_amount ?? 0) + 0.005 >= (float) ($source->GrandTotal ?? 0);

        return $paid ? (in_array($settings->paid_sale_type, ['FR', 'FT'], true) ? $settings->paid_sale_type : 'FR') : 'FT';
    }

    public function isFiscalDocument(Model $source): bool
    {
        if ($source instanceof SaleReturn) {
            return ($source->statut ?? null) === 'received';
        }

        return $source instanceof Sale && ($source->statut ?? null) === 'completed';
    }

    public function moduleActive(): bool
    {
        if (! AgtSetting::moduleEnabled()) {
            return false;
        }
        try {
            return app(TenantLimitsService::class)->hasFeature(self::FEATURE);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function sourceOf(AgtDocument $document): ?Model
    {
        $class = $document->source_type;
        if (! in_array($class, [Sale::class, SaleReturn::class], true)) {
            return null;
        }

        return $class::query()->whereNull('deleted_at')->find($document->source_id);
    }

    /** Printed security extract: characters 1, 11, 21 and 31 of the signature. */
    public static function hashExtract(string $jws): string
    {
        $signature = (string) (explode('.', $jws)[2] ?? '');
        $extract = '';
        foreach ([0, 10, 20, 30] as $position) {
            $extract .= $signature[$position] ?? '';
        }

        return $extract;
    }

    protected function qrPayload(AgtDocument $document, string $nif, string $customerNif): string
    {
        $producer = AgtProducerSetting::current();
        $template = $producer ? $producer->qrTemplate() : AgtProducerSetting::DEFAULT_QR_TEMPLATE;
        $fmt = fn ($n) => number_format((float) $n, 2, '.', '');

        return strtr($template, [
            '{nif}' => $nif,
            '{document_no}' => $document->document_no,
            '{document_type}' => $document->document_type,
            '{document_date}' => $document->document_date->toDateString(),
            '{gross_total}' => $fmt($document->gross_total),
            '{tax_payable}' => $fmt($document->tax_payable),
            '{customer_nif}' => $customerNif,
            '{hash}' => (string) $document->hash_extract,
        ]);
    }

    protected function lockSeries(AgtSetting $settings, string $type, int $year): AgtSeries
    {
        $candidates = AgtSeries::query()
            ->where('environment', $settings->environment)
            ->where('document_type', $type)
            ->where('series_year', $year)
            ->where('contingency', 'N')
            ->where('active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $series) {
            if ($series->next_number <= $series->last_number) {
                return $series;
            }
            $series->update(['active' => false]); // exhausted
        }

        throw new RuntimeException("No active AGT series for {$type} {$year} ({$settings->environment}). Request one in AGT settings > Series.");
    }

    protected function readySettings(): AgtSetting
    {
        $settings = AgtSetting::current();
        if (! $settings) {
            throw new RuntimeException('AGT tables are not migrated for this tenant.');
        }
        if (! EInvoicingProviderSetting::platformEnabled('agt')) {
            throw new RuntimeException('AGT e-invoicing is temporarily disabled by the platform administrator.');
        }
        if (! $settings->isReady()) {
            throw new RuntimeException('Complete the AGT settings first: taxpayer NIF and the AGT-issued private key.');
        }

        return $settings;
    }

    protected function errorText($errors): string
    {
        return collect(is_array($errors) ? $errors : [])
            ->map(function ($e) {
                if (! is_array($e)) {
                    return trim((string) $e);
                }
                $code = $e['idError'] ?? $e['errorCode'] ?? '';
                $text = $e['descriptionError'] ?? $e['errorDescription'] ?? '';

                return trim($code.' '.$text);
            })
            ->filter()
            ->implode(' | ');
    }

    protected function hasError($errors, string $code): bool
    {
        return collect(is_array($errors) ? $errors : [])
            ->contains(fn ($e) => is_array($e) && (($e['idError'] ?? $e['errorCode'] ?? null) === $code));
    }
}
