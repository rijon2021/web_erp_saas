<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One weekday of a staff member's working hours ("HH:MM", optional break). */
class BookingStaffHour extends Model
{
    protected $table = 'bk_staff_hours';

    protected $fillable = ['staff_id', 'weekday', 'start', 'end', 'break_start', 'break_end'];

    protected $casts = [
        'staff_id' => 'integer',
        'weekday' => 'integer',
    ];
}
