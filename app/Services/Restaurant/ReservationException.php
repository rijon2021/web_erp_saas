<?php

namespace App\Services\Restaurant;

/** A booking or waitlist action that is not allowed (message shown to the user as-is). */
class ReservationException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
