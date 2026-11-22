<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpencartLog extends Model
{
    protected $table = 'opencart_logs';

    protected $fillable = ['action', 'level', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
    ];
}
