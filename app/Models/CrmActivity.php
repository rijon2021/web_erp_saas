<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A logged activity (status done: a call made, a note, a meeting held) or a
 * planned one (status open, with due_at: a task, a call to make). Linked to a
 * lead, a deal and/or a client.
 */
class CrmActivity extends Model
{
    use SoftDeletes;

    public const TYPES = ['call', 'meeting', 'email', 'note', 'task'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const OUTCOMES = ['connected', 'no_answer', 'voicemail', 'busy', 'wrong_number'];

    protected $table = 'crm_activities';

    protected static function booted()
    {
        // Activities feed the lead score (done count, recent activity).
        static::saved(fn (CrmActivity $a) => \App\Services\Crm\CrmScoringService::refresh($a->crm_lead_id));
        static::deleted(fn (CrmActivity $a) => \App\Services\Crm\CrmScoringService::refresh($a->crm_lead_id));
    }

    protected $fillable = [
        'type', 'subject', 'description', 'status', 'priority', 'due_at', 'duration_minutes', 'location', 'outcome',
        'done_at', 'crm_lead_id', 'crm_deal_id', 'client_id', 'owner_id', 'reminder_minutes', 'reminded_at', 'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'done_at' => 'datetime',
        'reminded_at' => 'datetime',
        'duration_minutes' => 'integer',
        'reminder_minutes' => 'integer',
        'crm_lead_id' => 'integer',
        'crm_deal_id' => 'integer',
        'client_id' => 'integer',
        'owner_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id')->withTrashed();
    }

    public function deal()
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id')->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->lt(Carbon::now());
    }
}
