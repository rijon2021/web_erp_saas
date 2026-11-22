<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A serial / IMEI unit a damage line took out of stock (now `damaged`).
 * See SerialNumberService (Damage section).
 */
class DamageDetailSerial extends Model
{
    protected $table = 'damage_detail_serials';

    protected $fillable = [
        'damage_id', 'damage_detail_id', 'product_serial_id', 'serial_number', 'from_status',
    ];

    protected $casts = [
        'damage_id' => 'integer',
        'damage_detail_id' => 'integer',
        'product_serial_id' => 'integer',
    ];

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }
}
