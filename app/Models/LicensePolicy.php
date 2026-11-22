<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Generated-key rules for a Software License product (product_variant_id NULL)
 * or one of its editions (variant rows override the product row).
 */
class LicensePolicy extends Model
{
    protected $table = 'license_policies';

    const DURATIONS = ['lifetime', 'days', 'months', 'years'];
    const RESTRICTIONS = ['none', 'domain', 'device'];

    protected $fillable = [
        'product_id', 'product_variant_id', 'key_format',
        'duration_type', 'duration_value', 'max_activations', 'restriction',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'duration_value' => 'integer',
        'max_activations' => 'integer',
    ];

    /** Expiry for a license starting at $from (NULL = lifetime). */
    public function expiryFrom(Carbon $from): ?Carbon
    {
        $n = max(1, (int) $this->duration_value);

        return match ($this->duration_type) {
            'days' => $from->copy()->addDays($n),
            'months' => $from->copy()->addMonthsNoOverflow($n),
            'years' => $from->copy()->addYearsNoOverflow($n),
            default => null,
        };
    }

    /** The policy for a product line: the edition's row, else the product's. */
    public static function forLine(int $productId, ?int $variantId): ?self
    {
        $rows = static::where('product_id', $productId)
            ->where(function ($q) use ($variantId) {
                $q->whereNull('product_variant_id');
                if ($variantId) {
                    $q->orWhere('product_variant_id', $variantId);
                }
            })
            ->get();

        return ($variantId ? $rows->firstWhere('product_variant_id', $variantId) : null)
            ?? $rows->firstWhere('product_variant_id', null);
    }
}
