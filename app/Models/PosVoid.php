<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A line taken off a POS cart while "Require manager approval to remove items"
 * is on (App\Http\Controllers\PosVoidsController). Never edited after insert.
 */
class PosVoid extends Model
{
    protected $table = 'pos_voids';

    protected $fillable = [
        'action', 'product_id', 'product_variant_id', 'product_name', 'product_code',
        'quantity', 'unit_price', 'amount', 'warehouse_id', 'cashier_id', 'approved_by',
        'approval_method', 'reason',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity' => 'double',
        'unit_price' => 'double',
        'amount' => 'double',
        'warehouse_id' => 'integer',
        'cashier_id' => 'integer',
        'approved_by' => 'integer',
    ];

    public const ACTIONS = ['remove_line', 'decrease_qty', 'clear_cart'];
}
