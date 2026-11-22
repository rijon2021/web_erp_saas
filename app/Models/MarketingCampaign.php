<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaign extends Model
{
    use SoftDeletes;

    protected $table = 'marketing_campaigns';

    /**
     * A sending campaign is touched after every delivered batch; one left
     * untouched this long has no live job (queue down, worker killed) and may
     * be sent again. Kept above the job's 20-minute timeout.
     */
    public const STALE_SENDING_MINUTES = 30;

    protected $appends = ['is_stuck'];

    protected $fillable = [
        'title', 'description', 'type', 'subject', 'message_content', 'attachment',
        'template_id', 'segment_id', 'all_customers', 'status', 'send_immediately',
        'scheduled_at', 'sent_at', 'total_recipients', 'sent_count', 'delivered_count',
        'failed_count', 'pending_count', 'created_by',
        'wa_template_name', 'wa_template_language', 'wa_template_params',
    ];

    protected $casts = [
        'wa_template_params' => 'array',
        'all_customers'    => 'boolean',
        'send_immediately' => 'boolean',
        'scheduled_at'     => 'datetime',
        'sent_at'          => 'datetime',
    ];

    public function getIsStuckAttribute(): bool
    {
        return $this->status === 'sending'
            && $this->updated_at
            && $this->updated_at->lt(now()->subMinutes(self::STALE_SENDING_MINUTES));
    }

    public function segment()
    {
        return $this->belongsTo(MarketingSegment::class, 'segment_id');
    }

    public function template()
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id');
    }

    public function recipients()
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
