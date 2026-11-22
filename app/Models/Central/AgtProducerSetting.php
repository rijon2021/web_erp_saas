<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Angola AGT software-producer profile. The platform operator registers the
 * software with AGT once; every tenant's request carries its signature
 * (jwsSoftwareSignature) and Basic-auth credentials.
 */
class AgtProducerSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'agt_producer_settings';

    protected $fillable = [
        'product_id', 'product_version', 'software_validation_number', 'producer_tax_id',
        'signature_version', 'software_private_key', 'test_username', 'test_password',
        'production_username', 'production_password', 'qr_template', 'certification_text',
    ];

    protected $casts = [
        'signature_version' => 'integer',
        'software_private_key' => 'encrypted',
        'test_password' => 'encrypted',
        'production_password' => 'encrypted',
    ];

    /**
     * Printed QR content. The AGT API documentation does not define the QR
     * payload; the operator replaces this once AGT confirms the format.
     */
    public const DEFAULT_QR_TEMPLATE = '{nif}|{document_no}|{document_date}|{gross_total}|{hash}';

    public const DEFAULT_CERTIFICATION_TEXT = 'Processado por programa validado n.º {number}';

    public const QR_PLACEHOLDERS = ['{nif}', '{document_no}', '{document_type}', '{document_date}', '{gross_total}', '{tax_payable}', '{customer_nif}', '{hash}'];

    public static function current(): ?self
    {
        try {
            return static::query()->first() ?: static::query()->create([
                'product_version' => '1.0',
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} */
    public function credentials(string $environment): array
    {
        return $environment === 'production'
            ? [(string) $this->production_username, (string) $this->production_password]
            : [(string) $this->test_username, (string) $this->test_password];
    }

    /** Producer fields still missing before tenants can talk to AGT. */
    public function missing(string $environment): array
    {
        $missing = [];
        foreach (['product_id', 'product_version', 'software_validation_number', 'software_private_key'] as $field) {
            if (empty($this->{$field})) {
                $missing[] = $field;
            }
        }
        [$user, $password] = $this->credentials($environment);
        if ($user === '' || $password === '') {
            $missing[] = $environment.'_credentials';
        }

        return $missing;
    }

    public function softwareInfoDetail(): array
    {
        $detail = [
            'productId' => (string) $this->product_id,
            'productVersion' => (string) $this->product_version,
            'softwareValidationNumber' => (string) $this->software_validation_number,
        ];
        if ($this->signature_version) {
            $detail['signatureVersion'] = (int) $this->signature_version;
        }

        return $detail;
    }

    public function certificationLine(): string
    {
        $template = $this->certification_text ?: self::DEFAULT_CERTIFICATION_TEXT;

        return str_replace('{number}', (string) $this->software_validation_number, $template);
    }

    public function qrTemplate(): string
    {
        return $this->qr_template ?: self::DEFAULT_QR_TEMPLATE;
    }
}
