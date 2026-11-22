<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One value of a product option (e.g. "Red" of "Color").
 */
class ProductOptionValue extends Model
{
    protected $table = 'product_option_values';

    protected $fillable = ['product_option_id', 'value', 'position', 'swatch'];

    protected $casts = [
        'product_option_id' => 'integer',
        'position' => 'integer',
    ];

    public function option()
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_option_values', 'product_option_value_id', 'product_variant_id');
    }
}
