<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalHandoverItem extends Model
{
    protected $table = 'rental_handover_items';

    protected $fillable = [
        'rental_handover_id', 'rental_contract_item_id', 'rental_item_id', 'item_name', 'quantity',
        'condition', 'meter_reading', 'damage_notes', 'damage_charge', 'needs_maintenance',
    ];

    protected $casts = [
        'rental_handover_id' => 'integer',
        'rental_contract_item_id' => 'integer',
        'rental_item_id' => 'integer',
        'quantity' => 'integer',
        'meter_reading' => 'decimal:2',
        'damage_charge' => 'decimal:2',
        'needs_maintenance' => 'boolean',
    ];
}
