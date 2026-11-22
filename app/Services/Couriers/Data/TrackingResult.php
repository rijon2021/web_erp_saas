<?php

namespace App\Services\Couriers\Data;

use Carbon\CarbonInterface;

/**
 * A consignment's current state. $events may be the full history or only
 * new lines; duplicates are dropped by ShipmentEvent's hash.
 */
final class TrackingResult
{
    /**
     * @param  TrackingEvent[]  $events
     * @param  ?string  $codStatus  CourierStatus-independent COD state:
     *                              pending | collected | remitted, null = not reported
     */
    public function __construct(
        public readonly ?string $status,
        public readonly ?string $rawStatus = null,
        public readonly array $events = [],
        public readonly ?CarbonInterface $deliveredAt = null,
        public readonly ?CarbonInterface $returnedAt = null,
        public readonly ?string $codStatus = null,
        public readonly ?string $codReference = null,
        public readonly ?CarbonInterface $codRemittedAt = null,
        public readonly ?float $charges = null,
        public readonly array $meta = [],
    ) {
    }
}
