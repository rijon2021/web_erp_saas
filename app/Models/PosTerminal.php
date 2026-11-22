<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical POS device (card terminal / fiscal cash register). See the
 * 2026_10_28_000003 migration for the column meanings; the device protocol
 * lives in App\Services\PosTerminals.
 */
class PosTerminal extends Model
{
    use SoftDeletes;

    public const MODE_CLOUD = 'cloud';
    public const MODE_BRIDGE = 'bridge';

    public const FISCAL_PAYMENT_ONLY = 'payment_only';
    public const FISCAL_FULL_RECEIPT = 'full_receipt';

    public const AUTH_TYPES = ['bearer', 'basic', 'header', 'hmac', 'none'];

    protected $table = 'pos_terminals';

    protected $attributes = [
        'connection_mode' => self::MODE_BRIDGE,
        'auth_type' => 'bearer',
        'fiscal_mode' => self::FISCAL_PAYMENT_ONLY,
        'is_test' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'name', 'driver', 'warehouse_id', 'payment_method_id', 'connection_mode',
        'api_base_url', 'bridge_url', 'auth_type', 'api_key', 'api_secret',
        'merchant_id', 'terminal_id', 'serial_number', 'ip_address', 'port',
        'fiscal_mode', 'settings', 'is_test', 'is_active', 'last_seen_at',
        'last_status', 'created_by',
    ];

    protected $hidden = ['api_key', 'api_secret'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'payment_method_id' => 'integer',
        'port' => 'integer',
        'settings' => 'array',
        'is_test' => 'boolean',
        'is_active' => 'boolean',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
        'last_seen_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function transactions()
    {
        return $this->hasMany(PosTerminalTransaction::class);
    }

    public function usesBridge(): bool
    {
        return $this->connection_mode === self::MODE_BRIDGE;
    }

    /** One value from the settings JSON. */
    public function setting(string $key, $default = null)
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /** Seconds the device has to finish a card operation (customer taps, PIN…). */
    public function timeoutSeconds(): int
    {
        return max(15, min(300, (int) $this->setting('timeout_seconds', 120)));
    }

    /** Last 4 characters of a secret, for "…1234" hints. Null when unset. */
    public static function hint(?string $secret): ?string
    {
        return $secret ? '…'.substr($secret, -4) : null;
    }
}
