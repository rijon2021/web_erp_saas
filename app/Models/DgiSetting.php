<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DgiSetting extends Model
{
    protected $fillable = [
        'environment', 'nif', 'rccm', 'id_nat', 'company_name', 'address',
        'def_number', 'api_url', 'api_token', 'tax_group_map',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'tax_group_map' => 'array',
    ];

    /**
     * Current tenant's DGI settings row (single-row table), created lazily.
     * Returns null when the table does not exist yet (tenant not migrated).
     */
    public static function current(): ?self
    {
        try {
            return static::query()->first() ?: static::query()->create(['environment' => 'test']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Master switch: System Settings > Features. */
    public static function moduleEnabled(): bool
    {
        try {
            $setting = Setting::where('deleted_at', '=', null)->first();

            return (bool) ($setting->dgi_einvoice_enabled ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
