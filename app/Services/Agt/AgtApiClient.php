<?php

namespace App\Services\Agt;

use App\Models\AgtLog;
use App\Models\AgtSetting;
use App\Models\Central\AgtProducerSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HTTP wrapper around the AGT e-invoicing services. Every call carries the
 * producer's Basic-auth credentials and jwsSoftwareSignature; calls about a
 * taxpayer also carry a jwsSignature made with the tenant's AGT-issued key.
 * Every exchange is logged to agt_logs (never credentials or keys).
 */
class AgtApiClient
{
    public function __construct(
        protected AgtSetting $settings,
        protected AgtProducerSetting $producer,
    ) {
    }

    public static function make(AgtSetting $settings): self
    {
        $producer = AgtProducerSetting::current();
        if (! $producer) {
            throw new RuntimeException('AGT producer settings are not migrated on the central database.');
        }
        $missing = $producer->missing($settings->environment);
        if ($missing) {
            throw new RuntimeException('AGT e-invoicing is not configured by the platform administrator yet (missing: '.implode(', ', $missing).').');
        }

        return new self($settings, $producer);
    }

    /** POST registarFactura — returns requestID (validation happens later). */
    public function registerInvoices(array $documents, ?int $documentId = null): array
    {
        $body = $this->envelope() + [
            'submissionUUID' => (string) Str::uuid(),
            'numberOfEntries' => count($documents),
            'documents' => array_values($documents),
        ];

        return $this->send('registarFactura', $body, $documentId, ['documents' => count($documents)]);
    }

    /** POST obterEstado — verdict of a registarFactura request. */
    public function requestStatus(string $requestId, ?int $documentId = null): array
    {
        $body = $this->envelope() + [
            'submissionUUID' => (string) Str::uuid(),
            'requestID' => $requestId,
        ];
        $body['jwsSignature'] = $this->signRequest([
            'taxRegistrationNumber' => $this->nif(),
            'requestID' => $requestId,
        ]);

        return $this->send('obterEstado', $body, $documentId, ['requestID' => $requestId]);
    }

    /** POST solicitarSerie — ask AGT for a numbering series. */
    public function requestSeries(string $documentType, int $year, string $establishment, string $contingency = 'N'): array
    {
        $signed = [
            'taxRegistrationNumber' => $this->nif(),
            'seriesYear' => (string) $year,
            'documentType' => $documentType,
            'establishmentNumber' => $establishment,
            'seriesContingencyIndicator' => $contingency,
        ];
        $body = $this->envelope() + ['submissionUUID' => (string) Str::uuid()] + $signed;
        $body['jwsSignature'] = $this->signRequest($signed);

        return $this->send('solicitarSerie', $body, null, ['documentType' => $documentType, 'seriesYear' => $year]);
    }

    /** POST listarSeries — series registered for the taxpayer. */
    public function listSeries(array $filters = []): array
    {
        $body = $this->envelope() + array_filter($filters, fn ($v) => $v !== null && $v !== '');
        $body['jwsSignature'] = $this->signRequest(['taxRegistrationNumber' => $this->nif()]);

        return $this->send('listarSeries', $body, null, $filters);
    }

    /** POST consultarFactura — full detail of one communicated document. */
    public function getInvoice(string $documentNo, ?int $documentId = null): array
    {
        $body = $this->envelope() + [
            'submissionUUID' => (string) Str::uuid(),
            'documentNo' => $documentNo,
        ];
        $body['jwsSignature'] = $this->signRequest([
            'taxRegistrationNumber' => $this->nif(),
            'documentNo' => $documentNo,
        ]);

        return $this->send('consultarFactura', $body, $documentId, ['documentNo' => $documentNo]);
    }

    /** jwsDocumentSignature over the AGT-defined subset of a document. */
    public function signDocument(array $signedFields): string
    {
        return Jws::sign($signedFields, $this->taxpayerKey());
    }

    protected function signRequest(array $payload): string
    {
        return Jws::sign($payload, $this->taxpayerKey());
    }

    protected function envelope(): array
    {
        $detail = $this->producer->softwareInfoDetail();

        return [
            'schemaVersion' => (string) config('agt.schema_version', '2.0'),
            'taxRegistrationNumber' => $this->nif(),
            'submissionTimeStamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => [
                'softwareInfoDetail' => $detail,
                'jwsSoftwareSignature' => Jws::sign($detail, (string) $this->producer->software_private_key),
            ],
        ];
    }

    protected function nif(): string
    {
        return trim((string) $this->settings->tax_registration_number);
    }

    protected function taxpayerKey(): string
    {
        $key = (string) $this->settings->private_key;
        if ($key === '') {
            throw new RuntimeException('Upload the AGT-issued private key (Portal do Contribuinte) in AGT settings first.');
        }

        return $key;
    }

    protected function url(string $service): string
    {
        $env = config('agt.environments.'.$this->settings->environment) ?: config('agt.environments.test');

        return rtrim($env['base_url'], '/').'/'.$service;
    }

    /**
     * @return array{ok: bool, status: int|null, json: array|null, body: string|null, error: string|null}
     */
    protected function send(string $service, array $body, ?int $documentId, array $meta): array
    {
        $result = ['ok' => false, 'status' => null, 'json' => null, 'body' => null, 'error' => null];
        $url = $this->url($service);
        [$user, $password] = $this->producer->credentials($this->settings->environment);

        try {
            $response = Http::timeout((int) config('agt.timeout', 20))
                ->withBasicAuth($user, $password)
                ->acceptJson()
                ->asJson()
                ->post($url, $body);

            $result['status'] = $response->status();
            $result['json'] = $response->json();
            $result['body'] = $response->body();
            $result['ok'] = $response->successful();
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        try {
            AgtLog::create([
                'agt_document_id' => $documentId,
                'action' => $service,
                'endpoint' => $url,
                'http_status' => $result['status'],
                'request_meta' => ['environment' => $this->settings->environment] + $meta,
                'response_body' => $result['error'] ?: mb_substr((string) $result['body'], 0, 20000),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a submission.
        }

        return $result;
    }
}
