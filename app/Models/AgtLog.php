<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgtLog extends Model
{
    protected $fillable = [
        'agt_document_id', 'action', 'endpoint', 'http_status', 'request_meta', 'response_body',
    ];

    protected $casts = [
        'agt_document_id' => 'integer',
        'http_status' => 'integer',
        'request_meta' => 'array',
    ];
}
