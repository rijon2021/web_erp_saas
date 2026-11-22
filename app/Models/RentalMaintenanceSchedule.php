<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A preventive plan: open a maintenance job every N days/weeks/months/years and/or meter units. */
class RentalMaintenanceSchedule extends Model
{
    use SoftDeletes;

    protected $table = 'rental_maintenance_schedules';

    protected $fillable = [
        'rental_item_id', 'title', 'type', 'interval_value', 'interval_unit', 'meter_interval',
        'last_done_at', 'last_meter', 'next_due_date', 'next_due_meter', 'lead_days', 'duration_hours',
        'active', 'notes', 'created_by',
    ];

    protected $casts = [
        'rental_item_id' => 'integer',
        'interval_value' => 'integer',
        'meter_interval' => 'decimal:2',
        'last_done_at' => 'date',
        'last_meter' => 'decimal:2',
        'next_due_date' => 'date',
        'next_due_meter' => 'decimal:2',
        'lead_days' => 'integer',
        'duration_hours' => 'integer',
        'active' => 'boolean',
        'created_by' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id', 'id');
    }

    public function jobs()
    {
        return $this->hasMany(RentalMaintenance::class, 'schedule_id', 'id');
    }
}
