<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a lead's history (created, status changed, owner changed, imported…). */
class CrmLeadEvent extends Model
{
    protected $table = 'crm_lead_events';

    protected $fillable = ['crm_lead_id', 'type', 'note', 'meta', 'user_id'];

    protected $casts = [
        'crm_lead_id' => 'integer',
        'meta' => 'array',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
