<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much of one raw-material batch a production order material line consumed.
 *
 * Written on release (FEFO) and deleted again on cancel, so a finished batch
 * can be traced back to the exact material batches that went into it.
 */
class MrpProductionOrderMaterialBatch extends Model
{
    protected $table = 'mrp_production_order_material_batches';

    protected $fillable = [
        'production_order_material_id', 'product_batch_id', 'qty', 'unit_cost',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'qty' => 'double',
        'unit_cost' => 'double',
    ];

    public function material()
    {
        return $this->belongsTo(MrpProductionOrderMaterial::class, 'production_order_material_id');
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
