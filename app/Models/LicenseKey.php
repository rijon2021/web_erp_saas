<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One software license key (Software License products).
 *
 * The key itself is stored encrypted (`key_encrypted`); `key_hash` is a keyed
 * hash of the normalised key used for uniqueness and API look-ups, and
 * `key_last4` is what lists show. Read the plain key with plainKey().
 */
class LicenseKey extends Model
{
    protected $table = 'license_keys';

    const SOURCE_POOL = 'pool';
    const SOURCE_GENERATED = 'generated';

    const STATUS_AVAILABLE = 'available';
    const STATUS_SOLD = 'sold';
    const STATUS_RETURNED = 'returned';   // came back on a return / sale delete — admin restocks or voids
    const STATUS_VOID = 'void';           // written off
    const STATUS_SUSPENDED = 'suspended'; // generated: temporarily blocked
    const STATUS_REVOKED = 'revoked';     // generated: permanently blocked

    const STATUSES = ['available', 'sold', 'returned', 'void', 'suspended', 'revoked'];

    protected $fillable = [
        'product_id', 'product_variant_id', 'warehouse_id', 'source',
        'key_encrypted', 'key_hash', 'key_last4', 'status', 'cost', 'batch', 'adjustment_id',
        'sale_id', 'sale_detail_id', 'released_at', 'prev_status', 'online_order_id', 'client_id',
        'sold_at', 'delivered_at', 'starts_at', 'expires_at', 'max_activations', 'restriction',
        'note', 'status_reason', 'created_by', 'reminded_at',
    ];

    protected $hidden = ['key_encrypted', 'key_hash'];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'sale_id' => 'integer',
        'sale_detail_id' => 'integer',
        'released_at' => 'datetime',
        'online_order_id' => 'integer',
        'client_id' => 'integer',
        'max_activations' => 'integer',
        'cost' => 'double',
        'sold_at' => 'datetime',
        'delivered_at' => 'datetime',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    /** Normalised form used for hashing: generated keys are case-insensitive. */
    public static function normalize(string $key, bool $caseInsensitive = false): string
    {
        $key = trim(preg_replace('/\s+/u', ' ', $key) ?? '');

        return $caseInsensitive ? strtoupper($key) : $key;
    }

    public static function hashKey(string $normalizedKey): string
    {
        return hash_hmac('sha256', $normalizedKey, (string) config('app.key'));
    }

    /** Set key_encrypted / key_hash / key_last4 from a plain key. */
    public function setPlainKey(string $key): self
    {
        $normalized = self::normalize($key, $this->source === self::SOURCE_GENERATED);
        $this->key_encrypted = Crypt::encryptString($normalized);
        $this->key_hash = self::hashKey($normalized);
        $this->key_last4 = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $normalized) ?: $normalized, -4);

        return $this;
    }

    public function plainKey(): string
    {
        try {
            return Crypt::decryptString((string) $this->key_encrypted);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Masked form for lists: ••••-1A2B. */
    public function maskedKey(): string
    {
        return '••••-'.($this->key_last4 ?: '????');
    }

    public function isGenerated(): bool
    {
        return $this->source === self::SOURCE_GENERATED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Licensing state reported to the vendor's software and shown to customers:
     * active | expired | suspended | revoked | inactive (not sold / returned / void).
     */
    public function licenseState(): string
    {
        if ($this->status === self::STATUS_REVOKED) {
            return 'revoked';
        }
        if ($this->status === self::STATUS_SUSPENDED) {
            return 'suspended';
        }
        if ($this->status !== self::STATUS_SOLD) {
            return 'inactive';
        }

        return $this->isExpired() ? 'expired' : 'active';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseKeyEvent::class);
    }
}
