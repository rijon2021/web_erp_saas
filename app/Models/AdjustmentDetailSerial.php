<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A serial / IMEI unit moved by an adjustment line (in / out / kit / unkit).
 * See SerialNumberService (Adjustment section).
 */
class AdjustmentDetailSerial extends Model
{
    const ACTION_IN = 'in';
    const ACTION_OUT = 'out';
    const ACTION_KIT = 'kit';
    const ACTION_UNKIT = 'unkit';

    protected $table = 'adjustment_detail_serials';

    protected $fillable = [
        'adjustment_id', 'adjustment_detail_id', 'product_serial_id', 'serial_number', 'action',
    ];

    protected $casts = [
        'adjustment_id' => 'integer',
        'adjustment_detail_id' => 'integer',
        'product_serial_id' => 'integer',
    ];

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }
}
