<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached daily dashboard insights for one visibility scope. */
class AiInsight extends Model
{
    public $timestamps = false;

    protected $fillable = ['scope_key', 'insight_date', 'content', 'input_tokens', 'output_tokens', 'created_at'];

    protected $casts = [
        'content' => 'array',
        'insight_date' => 'date',
        'created_at' => 'datetime',
    ];
}
