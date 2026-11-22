<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    use HasFactory;

    // error_logs has only occurred_at (DB default CURRENT_TIMESTAMP) — with
    // timestamps on, every create() failed on the missing updated_at column.
    public $timestamps = false;

    protected $fillable = [
        'context', 'message', 'details', 'occurred_at',
    ];
}
