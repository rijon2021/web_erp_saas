<?php

namespace App\Services\EInvoicing;

use App\Models\AgtDocument;
use App\Models\AgtSetting;
use App\Models\Central\AgtProducerSetting;
use App\Models\DgiDocument;
use App\Models\DgiSetting;
use App\Support\FiscalQr;
use Illuminate\Database\Eloquent\Model;

/**
 * The fiscal block printed on receipts and invoices for the Angola (AGT)
 * and DR Congo (DGI) modules: a title, a few legal-mention lines and an
 * optional QR image (data URI). The legal wording stays in the
 * jurisdiction's language (Portuguese / French) whatever the UI locale.
 *
 * Shape: null | ['blocks' => [['title' => string, 'lines' => string[], 'qr' => ?string]]]
 */
class FiscalPrint
{
    public static function forSource(?Model $source): ?array
    {
        if (! $source) {
            return null;
        }

        $blocks = array_values(array_filter([
            self::agtBlock($source),
            self::dgiBlock($source),
        ]));

        return $blocks ? ['blocks' => $blocks] : null;
    }

    protected static function agtBlock(Model $source): ?array
    {
        $document = AgtDocument::forSource($source);
        if (! $document) {
            return null;
        }

        $settings = AgtSetting::current();
        $producer = AgtProducerSetting::current();
        $payload = $document->payload ?: [];
        $customerNif = (string) ($payload['customerTaxID'] ?? '');

        $lines = [
            'NIF: '.($settings->tax_registration_number ?? ''),
            'Cliente NIF: '.(($customerNif === '' || $customerNif === '999999999') ? 'Consumidor final' : $customerNif),
        ];
        if ($document->rejected_document_no) {
            $lines[] = 'Rectifica: '.$document->rejected_document_no;
        }
        if (! empty($payload['lines'][0]['referenceInfo']['reference'])) {
            $lines[] = 'Ref.: '.$payload['lines'][0]['referenceInfo']['reference'];
        }
        $lines[] = trim(($document->hash_extract ? $document->hash_extract.'-' : '').($producer ? $producer->certificationLine() : ''), '-');

        $status = [
            'pending' => 'Pendente de comunicação à AGT',
            'submitted' => 'Em validação pela AGT',
            'error' => 'Pendente de comunicação à AGT',
            'invalid' => 'Rejeitado pela AGT — documento a rectificar',
        ][$document->status] ?? null;
        if ($status) {
            $lines[] = $status;
        }

        return [
            'title' => $document->typeLabel().' '.$document->document_no,
            'lines' => array_values(array_filter($lines, fn ($l) => trim($l) !== '')),
            'qr' => FiscalQr::dataUri($document->qr_payload),
        ];
    }

    protected static function dgiBlock(Model $source): ?array
    {
        if (! DgiSetting::moduleEnabled()) {
            return null;
        }
        try {
            // The switch can outlive a plan downgrade: the plan decides too.
            if (! app(\App\Services\TenantLimitsService::class)->hasFeature('dgi')) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $settings = DgiSetting::current();
        if (! $settings || (empty($settings->nif) && empty($settings->rccm))) {
            return null;
        }

        $identity = array_filter([
            $settings->nif ? 'NIF : '.$settings->nif : null,
            $settings->rccm ? 'RCCM : '.$settings->rccm : null,
            $settings->id_nat ? 'Id. Nat. : '.$settings->id_nat : null,
        ]);
        $lines = [implode(' | ', $identity)];

        $document = DgiDocument::forSource($source);
        $qr = null;
        if ($document && $document->isCertified()) {
            $lines[] = 'DEF : '.($document->def_number ?: $settings->def_number);
            if ($document->counters) {
                $lines[] = 'Compteurs : '.$document->counters;
            }
            if ($document->auth_code) {
                $lines[] = 'Code : '.$document->auth_code;
            }
            if ($document->certified_at) {
                $lines[] = 'Certifiée le '.$document->certified_at->format('d/m/Y H:i:s');
            }
            $qr = FiscalQr::dataUri($document->qr_payload);
        } elseif ($document) {
            $lines[] = 'Facture en attente de certification DGI';
        }

        return [
            'title' => 'Facture normalisée',
            'lines' => $lines,
            'qr' => $qr,
        ];
    }
}
