<?php

namespace App\Services\Booking;

/** A website booking that cannot go ahead; the message is already translated for the visitor. */
class BookingWebsiteException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 422, private ?string $field = null)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function field(): ?string
    {
        return $this->field;
    }
}
