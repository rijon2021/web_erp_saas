<?php

namespace App\Services\Rental;

/** A booking refused on the website, with a message safe to show the visitor. */
class RentalWebsiteException extends \RuntimeException
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
