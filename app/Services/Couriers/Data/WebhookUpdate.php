<?php

namespace App\Services\Couriers\Data;

/** A status push from a courier, matched to a shipment by tracking number. */
final class WebhookUpdate
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly TrackingResult $result,
    ) {
    }
}
