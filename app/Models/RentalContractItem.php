<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalContractItem extends Model
{
    protected $table = 'rental_contract_items';

    protected $fillable = [
        'rental_contract_id', 'rental_item_id', 'item_name', 'item_code', 'quantity', 'rate', 'amount',
        'checked_out_at', 'returned_at',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'rental_item_id' => 'integer',
        'quantity' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id', 'id');
    }

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id', 'id');
    }
}
