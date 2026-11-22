<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a contract's history (created, activated, extended, signed…). */
class RentalContractEvent extends Model
{
    protected $table = 'rental_contract_events';

    protected $fillable = ['rental_contract_id', 'type', 'note', 'meta', 'user_id'];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'meta' => 'array',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
