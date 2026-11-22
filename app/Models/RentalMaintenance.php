<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A maintenance job on a rental item. */
class RentalMaintenance extends Model
{
    use SoftDeletes;

    protected $table = 'rental_maintenance';

    /** Photos directory, relative to public/. */
    public const FILE_DIR = 'rental_maintenance'; // under upload_path(): images/tenants/{id}/rental_maintenance

    public const TYPES = ['repair', 'service', 'inspection', 'cleaning', 'other'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'scheduled', 'in_progress', 'completed', 'cancelled'];
    /** Statuses that still need doing. */
    public const OPEN = ['open', 'scheduled', 'in_progress'];

    protected $fillable = [
        'number', 'rental_item_id', 'quantity', 'title', 'type', 'priority', 'status', 'source',
        'rental_contract_id', 'rental_handover_id', 'schedule_id',
        'scheduled_start', 'scheduled_end', 'started_at', 'completed_at',
        'employee_id', 'vendor', 'cost', 'meter_reading', 'description', 'resolution', 'photos',
        'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'rental_item_id' => 'integer',
        'quantity' => 'integer',
        'rental_contract_id' => 'integer',
        'rental_handover_id' => 'integer',
        'schedule_id' => 'integer',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'employee_id' => 'integer',
        'cost' => 'decimal:2',
        'meter_reading' => 'decimal:2',
        'photos' => 'array',
        'created_by' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id', 'id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function contract()
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id', 'id');
    }

    public function schedule()
    {
        return $this->belongsTo(RentalMaintenanceSchedule::class, 'schedule_id', 'id');
    }

    public function expenses()
    {
        return $this->hasMany(RentalExpense::class, 'rental_maintenance_id', 'id');
    }

    /** Past its planned start (or end) and still not done. */
    public function isOverdue(): bool
    {
        if (! in_array($this->status, self::OPEN, true)) {
            return false;
        }
        $limit = $this->status === 'in_progress' ? $this->scheduled_end : $this->scheduled_start;

        return $limit !== null && $limit->lt(Carbon::now());
    }

    /**
     * The window this job keeps the item unavailable, or null if it blocks
     * nothing (open/unscheduled, finished, cancelled). In-progress jobs with
     * no planned end block until they are completed.
     */
    public function blockWindow(): ?array
    {
        if ($this->status === 'in_progress') {
            return [$this->started_at ?? $this->scheduled_start ?? Carbon::now(), $this->scheduled_end];
        }
        if ($this->status === 'scheduled' && $this->scheduled_start) {
            return [$this->scheduled_start, $this->scheduled_end ?? $this->scheduled_start->copy()->addDay()];
        }

        return null;
    }

    public static function fileUrl(?string $file): ?string
    {
        return $file ? url(upload_path(self::FILE_DIR) . '/' . $file) : null;
    }
}
