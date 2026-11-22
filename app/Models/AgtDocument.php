<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgtDocument extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'invoice_ref', 'environment', 'document_type', 'agt_series_id',
        'sequence', 'document_no', 'document_date', 'system_entry_date', 'rejected_document_no',
        'net_total', 'tax_payable', 'gross_total', 'jws_document_signature', 'hash_extract',
        'qr_payload', 'status', 'request_id', 'validation_status', 'errors', 'payload', 'response',
        'attempts', 'submitted_at', 'checked_at', 'validated_at',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'sequence' => 'integer',
        'document_date' => 'date',
        'system_entry_date' => 'datetime',
        'net_total' => 'float',
        'tax_payable' => 'float',
        'gross_total' => 'float',
        'payload' => 'array',
        'response' => 'array',
        'attempts' => 'integer',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public const TYPE_LABELS = [
        'FT' => 'Factura',
        'FR' => 'Factura/Recibo',
        'NC' => 'Nota de Crédito',
        'ND' => 'Nota de Débito',
        'RC' => 'Recibo',
    ];

    public function series()
    {
        return $this->belongsTo(AgtSeries::class, 'agt_series_id');
    }

    /** Latest live (not superseded) document for a sale / return. */
    public static function forSource(Model $source): ?self
    {
        try {
            return static::query()
                ->where('source_type', get_class($source))
                ->where('source_id', $source->id)
                ->where('status', '!=', 'superseded')
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->document_type] ?? $this->document_type;
    }
}
