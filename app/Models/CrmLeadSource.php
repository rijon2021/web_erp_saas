<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLeadSource extends Model
{
    use SoftDeletes;

    protected $table = 'crm_lead_sources';

    protected $fillable = ['name', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
