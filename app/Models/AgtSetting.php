<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgtSetting extends Model
{
    protected $fillable = [
        'auto_submit', 'environment', 'tax_registration_number', 'company_name',
        'establishment_number', 'eac_code', 'private_key', 'paid_sale_type',
        'tax_code_map', 'default_exemption_code', 'default_unit',
    ];

    protected $casts = [
        'auto_submit' => 'boolean',
        'private_key' => 'encrypted',
        'tax_code_map' => 'array',
    ];

    /** IVA rate → AGT taxCode used until the tenant maps its own rates. */
    public const DEFAULT_TAX_CODE_MAP = ['14' => 'NOR', '7' => 'INT', '5' => 'RED'];

    /**
     * Current tenant's AGT settings row (single-row table), created lazily.
     * Returns null when the table does not exist yet (tenant not migrated).
     */
    public static function current(): ?self
    {
        try {
            return static::query()->first() ?: static::query()->create([
                'environment' => 'test',
                'tax_code_map' => self::DEFAULT_TAX_CODE_MAP,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Master switch: System Settings > Features. */
    public static function moduleEnabled(): bool
    {
        try {
            $setting = Setting::where('deleted_at', '=', null)->first();

            return (bool) ($setting->agt_einvoice_enabled ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Taxpayer side complete (the producer side is checked by AgtProducerSetting). */
    public function isReady(): bool
    {
        return ! empty($this->tax_registration_number) && ! empty($this->private_key);
    }

    public function taxCodeFor(float $rate): string
    {
        if ($rate <= 0) {
            return 'ISE';
        }
        $map = $this->tax_code_map ?: self::DEFAULT_TAX_CODE_MAP;
        foreach ($map as $mapped => $code) {
            if (abs((float) $mapped - $rate) < 0.0001) {
                return (string) $code;
            }
        }

        return 'OUT';
    }
}
