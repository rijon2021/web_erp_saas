<?php

namespace App\Models;

use App\Observers\ProductWarehouseObserver;
use Illuminate\Database\Eloquent\Model;

class product_warehouse extends Model
{
    protected $table = 'product_warehouse';

    /**
     * Watched so a stock movement can reach a connected Shopify shop at once
     * instead of on its next scheduled push. The observer does nothing unless
     * a shop has asked for instant pushes.
     */
    protected static function booted(): void
    {
        static::observe(ProductWarehouseObserver::class);
    }

    protected $fillable = [
        'product_id', 'warehouse_id', 'product_variant_id', 'qte', 'manage_stock',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'manage_stock' => 'integer',
        'qte' => 'double',
    ];

    /**
     * A variant product is stocked per variant. A parent-level row (no
     * variant) left on one is not a pickable item — there is no variant to
     * price it from — so product pickers must skip it.
     */
    public function scopeWithoutVariantParentRows($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('product_warehouse.product_variant_id')
                ->orWhereHas('product', function ($p) {
                    $p->where('type', '!=', 'is_variant');
                });
        });
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function productVariant()
    {
        return $this->belongsTo('App\Models\ProductVariant');
    }
}
