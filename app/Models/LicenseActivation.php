<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A domain or device a generated license key is activated on. */
class LicenseActivation extends Model
{
    protected $table = 'license_activations';

    protected $fillable = [
        'license_key_id', 'kind', 'identifier', 'label', 'ip',
        'activated_at', 'last_seen_at', 'deactivated_at',
    ];

    protected $casts = [
        'license_key_id' => 'integer',
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function scopeActive($q)
    {
        return $q->whereNull('deactivated_at');
    }

    public function licenseKey(): BelongsTo
    {
        return $this->belongsTo(LicenseKey::class);
    }
}
