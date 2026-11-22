<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'name', 'qty', 'cost', 'price', 'wholesale', 'min_price', 'code', 'gtin', 'image',
        'woocommerce_variation_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'qty' => 'double',
        'cost' => 'double',
        'price' => 'double',
        'wholesale' => 'double',
        'min_price' => 'double',
        'woocommerce_variation_id' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Option values this variant is the combination of (Color=Red, Size=S).
     * Empty for legacy flat-list variants.
     */
    public function optionValues()
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_values', 'product_variant_id', 'product_option_value_id');
    }
}
