<?php

namespace App\Services\Booking;

/**
 * A workflow step that cannot go ahead. `reason` is a machine code the admin
 * app translates (Bkm_Reason_<code>); `details` carries e.g. the clashing
 * bookings or the working hours from AvailabilityService::check().
 */
class BookingWorkflowException extends \RuntimeException
{
    public function __construct(
        string $message,
        public string $reason = 'invalid',
        public array $details = [],
        int $status = 422,
    ) {
        parent::__construct($message, $status);
    }

    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'reason' => $this->reason] + $this->details;
    }
}
