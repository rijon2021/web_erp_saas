<?php

namespace App\Services\Couriers\Data;

use Carbon\CarbonInterface;

/** One history line as the courier reported it. */
final class TrackingEvent
{
    public function __construct(
        public readonly ?string $status,            // CourierStatus value, null = unmapped
        public readonly ?string $rawStatus,
        public readonly ?CarbonInterface $at = null,
        public readonly ?string $location = null,
        public readonly ?string $description = null,
        public readonly array $payload = [],
    ) {
    }
}
