<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An open step of a pipeline; `probability` is the % chance to win from here. */
class CrmPipelineStage extends Model
{
    use SoftDeletes;

    protected $table = 'crm_pipeline_stages';

    protected $fillable = ['crm_pipeline_id', 'name', 'color', 'probability', 'rot_days', 'sort_order'];

    protected $casts = [
        'crm_pipeline_id' => 'integer',
        'probability' => 'integer',
        'rot_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function pipeline()
    {
        return $this->belongsTo(CrmPipeline::class, 'crm_pipeline_id')->withTrashed();
    }
}
