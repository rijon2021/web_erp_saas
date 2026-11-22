<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of a shipment's tracking timeline. */
class ShipmentEvent extends Model
{
    protected $table = 'shipment_events';

    protected $fillable = [
        'shipment_id', 'status', 'raw_status', 'description', 'location',
        'event_at', 'source', 'hash', 'payload',
    ];

    protected $casts = [
        'shipment_id' => 'integer',
        'event_at' => 'datetime',
        'payload' => 'array',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    /** Stable dedupe key for an event as the courier reported it. */
    public static function hashFor(?string $rawStatus, ?string $eventAt, ?string $location, ?string $description): string
    {
        return sha1(implode('|', [
            mb_strtolower(trim((string) $rawStatus)),
            (string) $eventAt,
            mb_strtolower(trim((string) $location)),
            mb_strtolower(trim((string) $description)),
        ]));
    }
}
