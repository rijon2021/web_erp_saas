<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a deal's history (created, stage changed, won, lost…). */
class CrmDealEvent extends Model
{
    protected $table = 'crm_deal_events';

    protected $fillable = ['crm_deal_id', 'type', 'note', 'meta', 'user_id'];

    protected $casts = [
        'crm_deal_id' => 'integer',
        'meta' => 'array',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
