<?php

namespace App\Services\Couriers\Data;

use App\Services\Couriers\CourierStatus;

/** What the courier returned for a new booking. */
final class BookingResult
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $courierReference = null,
        public readonly ?string $labelUrl = null,
        public readonly ?float $charges = null,
        public readonly string $status = CourierStatus::BOOKED,
        public readonly ?string $rawStatus = null,
        public readonly array $meta = [],           // raw response, kept on the shipment
    ) {
    }
}
