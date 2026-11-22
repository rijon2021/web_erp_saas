<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales goal for one rep (user_id) or the whole team (user_id null) over a
 * month or a quarter starting at period_start. Actuals are computed live by
 * CrmReportService::actual().
 */
class CrmTarget extends Model
{
    protected $table = 'crm_targets';

    public const PERIODS = ['month', 'quarter'];

    /** metric => is it money */
    public const METRICS = [
        'won_value' => true,
        'won_deals' => false,
        'new_leads' => false,
        'converted_leads' => false,
        'activities_done' => false,
    ];

    protected $fillable = ['user_id', 'period_type', 'period_start', 'metric', 'target_value', 'created_by'];

    protected $casts = [
        'user_id' => 'integer',
        'period_start' => 'date',
        'target_value' => 'decimal:2',
    ];

    /** [start, end] of the period that contains $date (end = last second). */
    public static function bounds(string $type, $date = null): array
    {
        $d = Carbon::parse($date ?? now());
        $start = $type === 'quarter' ? $d->copy()->firstOfQuarter()->startOfDay() : $d->copy()->startOfMonth();
        $end = $type === 'quarter' ? $start->copy()->lastOfQuarter()->endOfDay() : $start->copy()->endOfMonth();

        return [$start, $end];
    }
}
