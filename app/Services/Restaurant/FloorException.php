<?php

namespace App\Services\Restaurant;

/** A table action that is not allowed in the table's current state (shown to the user as-is). */
class FloorException extends \RuntimeException
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
