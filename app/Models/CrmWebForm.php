<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A public, embeddable form that creates CRM leads. Reached by its random
 * token at /lead-form/{token} (link or iframe). `fields` is an ordered list
 * of {key, label, required} picked from FIELDS.
 */
class CrmWebForm extends Model
{
    use SoftDeletes;

    protected $table = 'crm_web_forms';

    /** Field palette: key => [default label, input type]. */
    public const FIELDS = [
        'name' => ['Full name', 'text'],
        'first_name' => ['First name', 'text'],
        'last_name' => ['Last name', 'text'],
        'email' => ['Email', 'email'],
        'phone' => ['Phone', 'tel'],
        'company_name' => ['Company', 'text'],
        'job_title' => ['Job title', 'text'],
        'website' => ['Website', 'text'],
        'city' => ['City', 'text'],
        'country' => ['Country', 'text'],
        'estimated_value' => ['Budget', 'number'],
        'subject' => ['Subject', 'text'],
        'message' => ['Message', 'textarea'],
    ];

    public const DEFAULT_FIELDS = [
        ['key' => 'name', 'label' => 'Full name', 'required' => true],
        ['key' => 'email', 'label' => 'Email', 'required' => true],
        ['key' => 'phone', 'label' => 'Phone', 'required' => false],
        ['key' => 'company_name', 'label' => 'Company', 'required' => false],
        ['key' => 'message', 'label' => 'How can we help?', 'required' => false],
    ];

    protected $fillable = [
        'name', 'token', 'title', 'intro', 'fields', 'submit_label', 'success_message', 'redirect_url', 'consent_text',
        'accent_color', 'crm_lead_source_id', 'crm_lead_status_id', 'owner_id', 'tag_ids', 'is_active',
        'submissions', 'last_submission_at', 'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'tag_ids' => 'array',
        'is_active' => 'boolean',
        'crm_lead_source_id' => 'integer',
        'crm_lead_status_id' => 'integer',
        'owner_id' => 'integer',
        'submissions' => 'integer',
        'last_submission_at' => 'datetime',
    ];

    public static function newToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::withTrashed()->where('token', $token)->exists());

        return $token;
    }

    public function source()
    {
        return $this->belongsTo(CrmLeadSource::class, 'crm_lead_source_id')->withTrashed();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function url(): string
    {
        return url('lead-form/' . $this->token);
    }

    /** The configured fields, cleaned against the palette. */
    public function fieldList(): array
    {
        $out = [];
        foreach ((array) ($this->fields ?: self::DEFAULT_FIELDS) as $f) {
            $key = $f['key'] ?? null;
            if (! isset(self::FIELDS[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'key' => $key,
                'label' => trim((string) ($f['label'] ?? '')) ?: self::FIELDS[$key][0],
                'type' => self::FIELDS[$key][1],
                'required' => ! empty($f['required']),
            ];
        }

        return array_values($out);
    }
}
