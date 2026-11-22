<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingMethod extends Model
{
    use SoftDeletes;

    /** Fixed price set by the store. */
    public const TYPE_FLAT = 'flat';

    /** Real carrier prices quoted by Shippo for each cart + address. */
    public const TYPE_SHIPPO = 'shippo';

    /** Printful's own rates for the cart's print-on-demand items. */
    public const TYPE_PRINTFUL = 'printful';

    protected $fillable = ['name', 'price', 'active', 'sort_order', 'type', 'live_settings'];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'live_settings' => 'array',
    ];

    /** Quoted live by a carrier (the column is missing until the 2026_10_27 migration runs). */
    public function isLive(): bool
    {
        return in_array($this->type ?? self::TYPE_FLAT, [self::TYPE_SHIPPO, self::TYPE_PRINTFUL], true);
    }

    public function isPrintful(): bool
    {
        return ($this->type ?? self::TYPE_FLAT) === self::TYPE_PRINTFUL;
    }

    /** One live-rate setting, $default when unset or blank. */
    public function liveSetting(string $key, $default = null)
    {
        $value = ($this->live_settings ?? [])[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function regions(): HasMany
    {
        return $this->hasMany(ShippingMethodRegion::class);
    }

    /**
     * A method is available for a country when it has no region rows
     * (available everywhere) or one of its region rows matches.
     */
    public function availableForCountry(?string $country): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->regions->isEmpty()) {
            return true;
        }

        if (! $country) {
            return false;
        }

        // Compare normalized ISO codes, not raw strings: the shopper's
        // "mexico" and the admin's "México" are the same region.
        return $this->regions->contains(
            fn ($r) => \App\Services\CountryService::sameCountry($r->country, $country)
        );
    }
}
