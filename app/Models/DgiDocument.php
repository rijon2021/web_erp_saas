<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DgiDocument extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'invoice_ref', 'environment', 'status', 'def_number',
        'counters', 'auth_code', 'certified_at', 'qr_payload', 'errors', 'payload', 'response',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'certified_at' => 'datetime',
        'payload' => 'array',
        'response' => 'array',
    ];

    public static function forSource(Model $source): ?self
    {
        try {
            return static::query()
                ->where('source_type', get_class($source))
                ->where('source_id', $source->id)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isCertified(): bool
    {
        return $this->status === 'certified';
    }
}
