<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level state of one e-invoicing provider: the operator's
 * kill-switch and the defaults handed to tenants that have not chosen
 * their own. Read from tenant context too (ZatcaSetting, FbrSetting,
 * the submit services), so every accessor is tolerant of a missing table.
 */
class EInvoicingProviderSetting extends Model
{
    protected $connection = 'central';

    protected $table = 'einvoicing_provider_settings';

    protected $fillable = ['provider', 'enabled', 'settings'];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
    ];

    public const PROVIDERS = ['zatca', 'fbr', 'agt', 'dgi'];

    /** Defaults applied when the stored JSON lacks a key. */
    public const DEFAULTS = [
        'zatca' => [
            'default_environment' => 'sandbox',
            'solution_name' => 'Rasheed',
            'solution_version' => '1.0',
        ],
        'fbr' => [
            'default_environment' => 'sandbox',
        ],
        'agt' => [],
        'dgi' => [],
    ];

    /** @var array<string, self|null> per-request memo */
    protected static array $memo = [];

    protected static ?bool $tableExists = null;

    public static function tableExists(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::connection('central')->hasTable('einvoicing_provider_settings');
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }

        return self::$tableExists;
    }

    /** Row for a provider, created on first access. Null when not migrated. */
    public static function for(string $provider): ?self
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! self::tableExists()) {
            return null;
        }

        if (array_key_exists($provider, self::$memo)) {
            return self::$memo[$provider];
        }

        try {
            return self::$memo[$provider] = static::query()->firstOrCreate(
                ['provider' => $provider],
                ['enabled' => true, 'settings' => self::DEFAULTS[$provider] ?? []]
            );
        } catch (\Throwable $e) {
            return self::$memo[$provider] = null;
        }
    }

    public static function forget(): void
    {
        self::$memo = [];
        self::$tableExists = null;
    }

    /**
     * Platform kill-switch. A missing table or row means "enabled" so an
     * un-migrated install behaves exactly as before.
     */
    public static function platformEnabled(string $provider): bool
    {
        $row = self::for($provider);

        return $row === null ? true : (bool) $row->enabled;
    }

    /** One default value, falling back to the built-in defaults. */
    public static function value(string $provider, string $key, mixed $fallback = null): mixed
    {
        $row = self::for($provider);
        $stored = $row ? ($row->settings ?? []) : [];

        if (array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== '') {
            return $stored[$key];
        }

        return self::DEFAULTS[$provider][$key] ?? $fallback;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $stored = $this->settings ?? [];

        if (array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== '') {
            return $stored[$key];
        }

        return self::DEFAULTS[$this->provider][$key] ?? $fallback;
    }

    public function putSettings(array $values): void
    {
        $this->settings = array_merge($this->settings ?? [], $values);
        $this->save();
        self::$memo[$this->provider] = $this;
    }
}
