<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLostReason extends Model
{
    use SoftDeletes;

    protected $table = 'crm_lost_reasons';

    protected $fillable = ['name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];
}
