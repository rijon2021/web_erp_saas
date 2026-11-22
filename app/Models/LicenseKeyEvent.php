<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Timeline entry for a license key (imported, sold, returned, revoked, revealed…). */
class LicenseKeyEvent extends Model
{
    protected $table = 'license_key_events';

    public $timestamps = false;

    protected $fillable = ['license_key_id', 'event', 'note', 'user_id', 'meta', 'created_at'];

    protected $casts = [
        'license_key_id' => 'integer',
        'user_id' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
