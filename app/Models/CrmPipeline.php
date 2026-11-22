<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmPipeline extends Model
{
    use SoftDeletes;

    protected $table = 'crm_pipelines';

    protected $fillable = ['name', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function stages()
    {
        return $this->hasMany(CrmPipelineStage::class, 'crm_pipeline_id')->orderBy('sort_order');
    }

    /** The pipeline new deals go to: the flagged default, else the first one. */
    public static function defaultPipeline(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? static::query()->orderBy('sort_order')->first();
    }
}
