<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One question/answer exchange; `transcript` holds its raw API messages. */
class AiMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'question', 'answer', 'transcript', 'tools_used', 'error',
        'input_tokens', 'output_tokens', 'created_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'transcript' => 'array',
        'tools_used' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'created_at' => 'datetime',
    ];
}
