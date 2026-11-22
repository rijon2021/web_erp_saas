<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The CRM module's single settings row. Always read it through current(),
 * which creates the row with the column defaults on first use.
 */
class CrmSetting extends Model
{
    protected $table = 'crm_settings';

    protected $fillable = [
        'lead_prefix', 'deal_prefix', 'default_owner', 'stale_lead_days', 'duplicate_check',
        'default_reminder_minutes', 'reminder_email', 'reminder_push',
        'capture_store_messages', 'capture_quote_requests', 'capture_property_inquiries', 'capture_portal_requests',
        'capture_whatsapp_unknown', 'capture_sources', 'capture_followup_hours', 'capture_notify', 'scoring_enabled',
        'stale_alerts', 'stale_alerts_sent_on', 'weekly_report', 'weekly_report_sent_on',
    ];

    protected $casts = [
        'capture_store_messages' => 'boolean',
        'capture_quote_requests' => 'boolean',
        'capture_property_inquiries' => 'boolean',
        'capture_portal_requests' => 'boolean',
        'capture_whatsapp_unknown' => 'boolean',
        'capture_sources' => 'array',
        'capture_followup_hours' => 'integer',
        'capture_notify' => 'boolean',
        'scoring_enabled' => 'boolean',
        'stale_alerts' => 'boolean',
        'stale_alerts_sent_on' => 'date',
        'weekly_report' => 'boolean',
        'weekly_report_sent_on' => 'date',
        'stale_lead_days' => 'integer',
        'duplicate_check' => 'boolean',
        'default_reminder_minutes' => 'integer',
        'reminder_email' => 'boolean',
        'reminder_push' => 'boolean',
    ];

    public static function current(): self
    {
        $setting = static::query()->orderBy('id')->first();

        if (! $setting) {
            $setting = static::create([]);
            $setting->refresh();
        }

        return $setting;
    }
}
