<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A serial / IMEI unit selected on a transfer line. See SerialNumberService
 * (Transfer section) for when the selection is applied to product_serials.
 */
class TransferDetailSerial extends Model
{
    protected $table = 'transfer_detail_serials';

    protected $fillable = [
        'transfer_id', 'transfer_detail_id', 'product_serial_id', 'serial_number',
    ];

    protected $casts = [
        'transfer_id' => 'integer',
        'transfer_detail_id' => 'integer',
        'product_serial_id' => 'integer',
    ];

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function transferDetail()
    {
        return $this->belongsTo(TransferDetail::class, 'transfer_detail_id');
    }
}
