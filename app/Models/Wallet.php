<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Owner-polymorphic stored-value wallet: suppliers (owner_type = 'provider',
 * SupplierWalletService) and customers (owner_type = 'client',
 * CustomerWalletService). Every balance change must go through the owning
 * service so a wallet_transactions row is written.
 */
class Wallet extends Model
{
    use SoftDeletes;

    public const OWNER_PROVIDER = 'provider';

    public const OWNER_CLIENT = 'client';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    protected $fillable = ['owner_type', 'owner_id', 'balance', 'currency', 'status'];

    protected $casts = [
        'owner_id' => 'integer',
        'balance' => 'double',
    ];

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'owner_id');
    }

    public function scopeProviders($query)
    {
        return $query->where('owner_type', self::OWNER_PROVIDER);
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'owner_id');
    }

    public function scopeClients($query)
    {
        return $query->where('owner_type', self::OWNER_CLIENT);
    }

    public function withdrawals()
    {
        return $this->hasMany(WalletWithdrawal::class, 'wallet_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
