<?php

namespace App\Services\Restaurant;

/** An order action that is not allowed (message shown to the user as-is). */
class OrderException extends \RuntimeException
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
