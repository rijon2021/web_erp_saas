<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Single-row central table holding the platform's UI Lib licence state.
 * See App\Services\Licensing\LicenseService for the rules; this model
 * only stores. The licence belongs to the platform owner, never a tenant.
 */
class UiLibLicense extends Model
{
    protected $connection = 'central';

    protected $table = 'ui_lib_license';

    protected $fillable = ['license', 'email', 'token', 'instance_id', 'last_code', 'last_checked_at', 'grace_started_at'];

    protected $casts = [
        'license' => 'encrypted',
        'last_checked_at' => 'datetime',
        'grace_started_at' => 'datetime',
    ];

    /**
     * The licence row, created lazily. Null when the table does not exist
     * yet (an install that has not run the central migrations), so callers
     * degrade to "unknown" instead of failing the request.
     */
    public static function current(): ?self
    {
        try {
            $row = static::query()->first();
            if (! $row) {
                $row = static::query()->create(['instance_id' => (string) Str::uuid()]);
            }

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The stored licence key, or '' when none / undecryptable (APP_KEY changed).
     */
    public function licenseKey(): string
    {
        try {
            return (string) ($this->license ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
