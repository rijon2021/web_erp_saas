<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product's (or variant's) own prices in one warehouse / branch.
 * NULL columns fall back to the product's normal price.
 * See App\Services\WarehousePrices for how they are read.
 */
class ProductWarehousePrice extends Model
{
    protected $table = 'product_warehouse_prices';

    protected $fillable = [
        'product_id', 'product_variant_id', 'warehouse_id',
        'price', 'wholesale_price', 'min_price',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'price' => 'double',
        'wholesale_price' => 'double',
        'min_price' => 'double',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
