<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One component of a bill of materials, quantified per BOM output. */
class MrpBomLine extends Model
{
    protected $table = 'mrp_bom_lines';

    protected $fillable = [
        'bom_id', 'product_id', 'product_variant_id', 'qty', 'unit_id',
        'scrap_pct', 'is_optional', 'notes', 'sort_order',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'qty' => 'double',
        'scrap_pct' => 'double',
        'is_optional' => 'boolean',
    ];

    public function bom()
    {
        return $this->belongsTo(MrpBom::class, 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Multiplier from the line's unit to the component's base (stock) unit.
     *
     * Same rule purchases use: a sub-unit with operator "*" holds operator_value
     * base units (a box of 12), one with "/" is a fraction of the base unit. A
     * line with no unit, or stated in the base unit itself, is already in base.
     */
    public function unitFactor(): float
    {
        if (! $this->unit_id) {
            return 1.0;
        }
        $unit = $this->unit;
        if (! $unit || ! $unit->base_unit) {
            return 1.0;
        }
        $value = (float) $unit->operator_value;
        if ($value <= 0) {
            return 1.0;
        }

        return $unit->operator === '/' ? 1 / $value : $value;
    }

    /** {@see qtyWithScrap()} expressed in the component's base unit. */
    public function baseQtyWithScrap(): float
    {
        return round($this->qtyWithScrap() * $this->unitFactor(), 6);
    }

    /**
     * Quantity to draw for one BOM output, scrap included.
     *
     * Scrap inflates the requirement rather than shrinking it: if 5% of a
     * component is expected to be spoiled, you must issue MORE than the net
     * need, not less.
     */
    public function qtyWithScrap(): float
    {
        return round((float) $this->qty * (1 + max(0, (float) $this->scrap_pct) / 100), 6);
    }
}
