<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product line on a deal (name / code / price snapshotted). tax_method
 * follows products: 1 = tax added on top (exclusive), 2 = price includes it.
 */
class CrmDealProduct extends Model
{
    protected $table = 'crm_deal_products';

    protected $fillable = [
        'crm_deal_id', 'product_id', 'product_variant_id', 'name', 'code', 'quantity', 'unit_price',
        'discount_percent', 'tax_percent', 'tax_method', 'total', 'sort_order',
    ];

    protected $casts = [
        'crm_deal_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_method' => 'integer',
        'total' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    /** Line total: qty × price − discount %, plus tax when it is exclusive. */
    public static function lineTotal(float $qty, float $price, float $discountPct, float $taxPct, int $taxMethod): float
    {
        $net = $qty * $price * (1 - min(100, max(0, $discountPct)) / 100);
        if ($taxMethod === 1 && $taxPct > 0) {
            $net *= 1 + $taxPct / 100;
        }

        return round($net, 2);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
