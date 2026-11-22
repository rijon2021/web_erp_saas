<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the lead score: "criterion (value) → ±points". See CrmScoringService. */
class CrmScoringRule extends Model
{
    protected $table = 'crm_scoring_rules';

    /** criterion => needs a value of this kind (null = no value). */
    public const CRITERIA = [
        'has_email' => null,
        'has_phone' => null,
        'has_company' => null,
        'is_company' => null,
        'rating' => 'rating',
        'source' => 'source',
        'captured_via' => 'channel',
        'value_at_least' => 'number',
        'country' => 'text',
        'tag' => 'tag',
        'recent_activity' => 'days',
        'no_activity' => 'days',
        'done_activities_at_least' => 'number',
        'has_open_deal' => null,
        'has_reply' => null,
    ];

    protected $fillable = ['criterion', 'value', 'points', 'is_active', 'sort_order'];

    protected $casts = [
        'points' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
