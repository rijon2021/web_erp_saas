<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PrestashopSetting extends Model
{
    protected $table = 'prestashop_settings';

    protected $fillable = [
        'enabled', 'store_url', 'api_key', 'default_language_id', 'warehouse_id', 'last_sync_at',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'enabled' => 'boolean',
        'default_language_id' => 'integer',
        'warehouse_id' => 'integer',
        'last_sync_at' => 'datetime',
    ];

    /**
     * The webservice key, encrypted at rest. Not the `encrypted` cast, which
     * throws on a key saved in plain text before this release: such a key is
     * read as it is until the 2026_11_06_000002 migration moves it.
     */
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return $value;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException $e) {
                    return $value;
                }
            },
            set: fn ($value) => $value === null || $value === '' ? $value : Crypt::encryptString($value),
        );
    }

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->store_url) && ! empty($this->api_key);
    }
}
