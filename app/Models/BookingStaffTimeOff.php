<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Days off for one staff member. Dates are inclusive; from_time limits the
 * first day ("off from 14:00"), to_time the last ("back at 11:00"); NULL means
 * the whole day.
 */
class BookingStaffTimeOff extends Model
{
    protected $table = 'bk_staff_time_off';

    protected $fillable = ['staff_id', 'start_date', 'end_date', 'from_time', 'to_time', 'reason'];

    protected $casts = [
        'staff_id' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];
}
