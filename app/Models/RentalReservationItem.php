<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalReservationItem extends Model
{
    protected $table = 'rental_reservation_items';

    protected $fillable = [
        'rental_reservation_id', 'rental_item_id', 'item_name', 'item_code', 'quantity', 'rate', 'amount',
    ];

    protected $casts = [
        'rental_reservation_id' => 'integer',
        'rental_item_id' => 'integer',
        'quantity' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id', 'id');
    }
}
