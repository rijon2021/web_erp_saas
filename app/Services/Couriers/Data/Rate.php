<?php

namespace App\Services\Couriers\Data;

/**
 * One priced option from a shipping aggregator. `id` is what book() accepts
 * back in ShipmentRequest::$service to buy exactly this option.
 */
final class Rate
{
    public function __construct(
        public readonly string $id,
        public readonly string $carrier,
        public readonly string $service,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?int $days = null,
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'amount' => round($this->amount, 2),
            'currency' => $this->currency,
            'days' => $this->days,
        ];
    }
}
