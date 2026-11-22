<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One option dimension of a variable product (e.g. "Color").
 * Products without rows here are legacy flat-list variant products.
 */
class ProductOption extends Model
{
    protected $table = 'product_options';

    protected $fillable = ['product_id', 'name', 'position'];

    protected $casts = [
        'product_id' => 'integer',
        'position' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class, 'product_option_id')->orderBy('position')->orderBy('id');
    }
}
