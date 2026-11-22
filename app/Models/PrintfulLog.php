<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintfulLog extends Model
{
    protected $table = 'printful_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];
}
