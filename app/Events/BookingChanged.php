<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking moved through the workflow (App\Services\Booking\BookingWorkflow),
 * or was sent from the website (BookingWebsiteService::book → `requested`).
 * $event: requested | confirmed | declined | cancelled | rescheduled | arrived |
 * no_show | completed | restored | charged. Notifications (phase 6) listen to this.
 */
class BookingChanged
{
    use Dispatchable;

    public function __construct(
        public Booking $booking,
        public string $event,
        public ?string $fromStatus = null,
        public ?int $byUserId = null,
    ) {}
}
