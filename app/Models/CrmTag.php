<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmTag extends Model
{
    use SoftDeletes;

    protected $table = 'crm_tags';

    protected $fillable = ['name', 'color'];

    public function leads()
    {
        return $this->morphedByMany(CrmLead::class, 'taggable', 'crm_taggables', 'crm_tag_id', 'taggable_id');
    }
}
