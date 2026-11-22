<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Leads that match these conditions go to this rep / rotate between these
 * reps." Rules are tried in sort_order; the first match wins.
 * conditions: {channels: [], source_ids: [], countries: [], min_value: n}
 */
class CrmAssignmentRule extends Model
{
    protected $table = 'crm_assignment_rules';

    public const METHODS = ['user', 'round_robin'];

    /** Where a lead came from (crm_leads.captured_via, plus manual / import). */
    public const CHANNELS = ['manual', 'import', 'web_form', 'store_message', 'quote_request', 'property_inquiry', 'portal_request', 'whatsapp'];

    protected $fillable = ['name', 'is_active', 'sort_order', 'conditions', 'method', 'user_ids', 'last_user_id', 'assigned_count'];

    protected $casts = [
        'is_active' => 'boolean',
        'conditions' => 'array',
        'user_ids' => 'array',
        'last_user_id' => 'integer',
        'assigned_count' => 'integer',
        'sort_order' => 'integer',
    ];
}
