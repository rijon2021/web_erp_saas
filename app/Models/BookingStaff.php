<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bookable staff member (bk_staff): an HRM employee, a user account or just
 * a name. Working hours live in bk_staff_hours, extra days off in
 * bk_staff_time_off; approved HRM leave and company holidays are read live by
 * App\Services\Booking\AvailabilityService.
 */
class BookingStaff extends Model
{
    use SoftDeletes;

    protected $table = 'bk_staff';

    public const PHOTO_DIR = 'booking_staff';

    /** ISO weekday numbers, Monday first. */
    public const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    protected $fillable = [
        'employee_id', 'user_id', 'name', 'title', 'color', 'photo', 'warehouse_id', 'bookable_online',
        'show_on_website', 'max_per_day', 'sort_order', 'active',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'bookable_online' => 'boolean',
        'show_on_website' => 'boolean',
        'max_per_day' => 'integer',
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    /** @var array<string, bool> tenant DB name → staff tables exist */
    private static array $ready = [];

    /** Whether migration 2026_10_25_000005 has run (and the earlier booking ones). */
    public static function tablesReady(): bool
    {
        $key = (string) DB::connection()->getDatabaseName();

        if (! isset(self::$ready[$key])) {
            try {
                self::$ready[$key] = BookingServiceDetail::tablesReady()
                    && Schema::hasTable('bk_staff') && Schema::hasTable('bk_service_staff');
            } catch (\Throwable $e) {
                self::$ready[$key] = false;
            }
        }

        return self::$ready[$key];
    }

    public static function forgetReady(): void
    {
        self::$ready = [];
    }

    public function hours()
    {
        return $this->hasMany(BookingStaffHour::class, 'staff_id');
    }

    public function timeOff()
    {
        return $this->hasMany(BookingStaffTimeOff::class, 'staff_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Service products this person does (none linked = the service is open to everyone). */
    public function services()
    {
        return $this->belongsToMany(Product::class, 'bk_service_staff', 'staff_id', 'product_id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo ? url(upload_path(self::PHOTO_DIR).'/'.$this->photo) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
