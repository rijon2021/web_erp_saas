<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A POS sale rung up offline and replayed by a till's sync — see
 * App\Services\Pos\OfflineSaleRecorder and Sales > Offline Sales.
 */
class PosOfflineSale extends Model
{
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'pos_offline_sales';

    protected $fillable = [
        'sale_uuid', 'offline_id', 'user_id', 'warehouse_id', 'client_id', 'grand_total', 'payload',
        'status', 'error_message', 'status_code', 'attempts', 'sale_id', 'offline_created_at',
        'last_attempt_at', 'resolved_by', 'resolved_at', 'resolution', 'note', 'device',
    ];

    protected $casts = [
        'payload' => 'array',
        'grand_total' => 'float',
        'attempts' => 'integer',
        'status_code' => 'integer',
        'offline_created_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
