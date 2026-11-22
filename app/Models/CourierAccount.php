<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A tenant's account with one courier company. A tenant may hold several,
 * even for the same courier (e.g. one per warehouse or per brand).
 * `credentials` is write-only towards the SPA: the API returns only which
 * fields are set (see CourierAccountsController::present()).
 */
class CourierAccount extends Model
{
    use SoftDeletes;

    protected $table = 'courier_accounts';

    protected $fillable = [
        'courier', 'name', 'mode', 'credentials', 'settings', 'tracking_url',
        'pickup_contact', 'pickup_phone', 'pickup_address', 'pickup_city',
        'warehouse_id', 'default_weight', 'default_service', 'allow_open_parcel',
        'is_default', 'active', 'webhook_token',
        'last_tested_at', 'last_test_ok', 'last_test_message',
    ];

    protected $hidden = ['credentials', 'webhook_token'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'warehouse_id' => 'integer',
        'default_weight' => 'float',
        'allow_open_parcel' => 'boolean',
        'is_default' => 'boolean',
        'active' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_test_ok' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (empty($account->webhook_token)) {
                $account->webhook_token = Str::random(48);
            }
        });
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    /** One credential value, '' when unset. */
    public function credential(string $key): string
    {
        return (string) (($this->credentials ?? [])[$key] ?? '');
    }

    /** One driver setting, $default when unset. */
    public function setting(string $key, $default = null)
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function isTestMode(): bool
    {
        return $this->mode === 'test';
    }

    /** Make this the only default account. */
    public function makeDefault(): void
    {
        static::query()->where('id', '!=', $this->id)->where('is_default', true)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }
}
