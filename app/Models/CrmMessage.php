<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An email / SMS / WhatsApp sent from the CRM, or a WhatsApp reply matched to a lead or customer. */
class CrmMessage extends Model
{
    public const CHANNELS = ['email', 'sms', 'whatsapp', 'portal'];

    protected $table = 'crm_messages';

    protected static function booted()
    {
        // A reply from the lead raises its score.
        static::created(fn (CrmMessage $m) => $m->direction === 'in' ? \App\Services\Crm\CrmScoringService::refresh($m->crm_lead_id) : null);
    }

    protected $fillable = [
        'channel', 'direction', 'crm_lead_id', 'crm_deal_id', 'client_id', 'to', 'subject', 'body', 'status', 'error',
        'whatsapp_message_id', 'marketing_template_id', 'owner_id', 'read_at', 'user_id', 'portal_read_at',
    ];

    protected $casts = [
        'crm_lead_id' => 'integer',
        'crm_deal_id' => 'integer',
        'client_id' => 'integer',
        'whatsapp_message_id' => 'integer',
        'marketing_template_id' => 'integer',
        'owner_id' => 'integer',
        'user_id' => 'integer',
        'read_at' => 'datetime',
        'portal_read_at' => 'datetime',
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

    public function whatsapp()
    {
        return $this->belongsTo(WhatsappLog::class, 'whatsapp_message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
