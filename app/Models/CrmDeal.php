<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sales opportunity. Belongs to a lead, an existing client, or both (after
 * the lead is converted). `status` is open / won / lost; while open it sits
 * in one stage of its pipeline. When it has product lines and
 * value_from_products is set, `value` is the lines' total.
 */
class CrmDeal extends Model
{
    use SoftDeletes;

    public const STATUSES = ['open', 'won', 'lost'];

    protected $table = 'crm_deals';

    protected static function booted()
    {
        // An open deal raises its lead's score.
        static::saved(fn (CrmDeal $d) => \App\Services\Crm\CrmScoringService::refresh($d->crm_lead_id));
        static::deleted(fn (CrmDeal $d) => \App\Services\Crm\CrmScoringService::refresh($d->crm_lead_id));
    }

    protected $fillable = [
        'deal_number', 'title', 'crm_pipeline_id', 'crm_pipeline_stage_id', 'status', 'crm_lead_id', 'client_id',
        'crm_contact_id', 'quotation_id',
        'owner_id', 'value', 'value_from_products', 'expected_close_date', 'position', 'stage_entered_at',
        'won_at', 'lost_at', 'crm_lost_reason_id', 'lost_note', 'description', 'last_activity_at', 'created_by', 'portal_visible',
    ];

    protected $casts = [
        'portal_visible' => 'boolean',
        'crm_pipeline_id' => 'integer',
        'crm_pipeline_stage_id' => 'integer',
        'crm_lead_id' => 'integer',
        'client_id' => 'integer',
        'crm_contact_id' => 'integer',
        'quotation_id' => 'integer',
        'owner_id' => 'integer',
        'value' => 'decimal:2',
        'value_from_products' => 'boolean',
        'expected_close_date' => 'date',
        'position' => 'integer',
        'stage_entered_at' => 'datetime',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'crm_lost_reason_id' => 'integer',
        'last_activity_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function pipeline()
    {
        return $this->belongsTo(CrmPipeline::class, 'crm_pipeline_id')->withTrashed();
    }

    public function stage()
    {
        return $this->belongsTo(CrmPipelineStage::class, 'crm_pipeline_stage_id')->withTrashed();
    }

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id')->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contact()
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id')->withTrashed();
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lostReason()
    {
        return $this->belongsTo(CrmLostReason::class, 'crm_lost_reason_id')->withTrashed();
    }

    public function products()
    {
        return $this->hasMany(CrmDealProduct::class, 'crm_deal_id')->orderBy('sort_order')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(CrmDealEvent::class, 'crm_deal_id');
    }

    public function activities()
    {
        return $this->hasMany(CrmActivity::class, 'crm_deal_id');
    }

    /** The earliest open (planned) activity — the "next step". */
    public function nextActivity()
    {
        return $this->hasOne(CrmActivity::class, 'crm_deal_id')->ofMany(['due_at' => 'min', 'id' => 'min'], function ($q) {
            $q->where('status', 'open')->whereNotNull('due_at')->whereNull('deleted_at');
        });
    }

    public function tags()
    {
        return $this->morphToMany(CrmTag::class, 'taggable', 'crm_taggables', 'taggable_id', 'crm_tag_id')
            ->whereNull('crm_tags.deleted_at');
    }

    public function getMorphClass()
    {
        return 'deal';
    }

    /** Value weighted by the stage's chance to win (won = 100%, lost = 0). */
    public function weightedValue(): float
    {
        if ($this->status === 'won') {
            return (float) $this->value;
        }
        if ($this->status === 'lost') {
            return 0.0;
        }

        return round((float) $this->value * (int) optional($this->stage)->probability / 100, 2);
    }

    /** Idle in its stage longer than the stage's rot_days. */
    public function isRotting(): bool
    {
        $days = (int) optional($this->stage)->rot_days;
        if ($this->status !== 'open' || $days <= 0) {
            return false;
        }
        $since = $this->last_activity_at && $this->stage_entered_at
            ? $this->last_activity_at->max($this->stage_entered_at)
            : ($this->last_activity_at ?? $this->stage_entered_at ?? $this->created_at);

        return $since && $since->lt(Carbon::now()->subDays($days));
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->expected_close_date && $this->expected_close_date->lt(Carbon::today());
    }
}
