<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prospect that is not a client yet. `name` is kept in sync with the
 * person / company fields (see displayName()) so lists can search and sort
 * on one column. `phone_normalized` (last 9 digits) drives duplicate checks.
 */
class CrmLead extends Model
{
    use SoftDeletes;

    public const TYPES = ['person', 'company'];

    public const RATINGS = ['cold', 'warm', 'hot'];

    protected $table = 'crm_leads';

    protected $fillable = [
        'lead_number', 'type', 'name', 'first_name', 'last_name', 'company_name', 'job_title',
        'email', 'phone', 'phone_normalized', 'website', 'industry', 'address', 'city', 'country',
        'crm_lead_source_id', 'crm_lead_status_id', 'owner_id', 'rating', 'estimated_value', 'score',
        'description', 'crm_lost_reason_id', 'lost_note', 'lost_at', 'converted_at', 'client_id',
        'last_activity_at', 'created_by', 'captured_via', 'crm_web_form_id', 'capture_ref_id', 'marketing_opt_out', 'marketing_opt_out_at',
    ];

    protected $casts = [
        'crm_lead_source_id' => 'integer',
        'crm_lead_status_id' => 'integer',
        'owner_id' => 'integer',
        'estimated_value' => 'decimal:2',
        'score' => 'integer',
        'crm_lost_reason_id' => 'integer',
        'client_id' => 'integer',
        'created_by' => 'integer',
        'crm_web_form_id' => 'integer',
        'marketing_opt_out' => 'boolean',
        'marketing_opt_out_at' => 'datetime',
        'capture_ref_id' => 'integer',
        'lost_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::saved(fn (CrmLead $lead) => \App\Services\Crm\CrmScoringService::refresh($lead->id));
    }

    public function source()
    {
        return $this->belongsTo(CrmLeadSource::class, 'crm_lead_source_id')->withTrashed();
    }

    public function status()
    {
        return $this->belongsTo(CrmLeadStatus::class, 'crm_lead_status_id')->withTrashed();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lostReason()
    {
        return $this->belongsTo(CrmLostReason::class, 'crm_lost_reason_id')->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function webForm()
    {
        return $this->belongsTo(CrmWebForm::class, 'crm_web_form_id')->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(CrmLeadEvent::class, 'crm_lead_id');
    }

    public function activities()
    {
        return $this->hasMany(CrmActivity::class, 'crm_lead_id');
    }

    /** The earliest open (planned) activity — the "next step". */
    public function nextActivity()
    {
        return $this->hasOne(CrmActivity::class, 'crm_lead_id')->ofMany(['due_at' => 'min', 'id' => 'min'], function ($q) {
            $q->where('status', 'open')->whereNotNull('due_at')->whereNull('deleted_at');
        });
    }

    public function tags()
    {
        return $this->morphToMany(CrmTag::class, 'taggable', 'crm_taggables', 'taggable_id', 'crm_tag_id')
            ->whereNull('crm_tags.deleted_at');
    }

    /** The morph type stored in crm_taggables for leads. */
    public function getMorphClass()
    {
        return 'lead';
    }

    public static function displayName(array $d): string
    {
        $person = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        if (($d['type'] ?? 'person') === 'company') {
            return trim((string) ($d['company_name'] ?? '')) ?: $person;
        }

        return $person ?: trim((string) ($d['company_name'] ?? ''));
    }

    /** Last 9 digits: matches "+212 6 12 34 56 78" with "0612345678". */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 6 ? substr($digits, -9) : null;
    }

    public function statusKind(): string
    {
        return optional($this->status)->kind ?? 'open';
    }

    public function userName(?User $u): ?string
    {
        if (! $u) {
            return null;
        }

        return trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: $u->username;
    }
}
