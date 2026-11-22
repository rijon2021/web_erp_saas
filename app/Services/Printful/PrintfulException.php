<?php

namespace App\Services\Printful;

use RuntimeException;

/** A Printful API failure; `retryable` = network, rate limit or a 5xx. */
class PrintfulException extends RuntimeException
{
    public function __construct(string $message, private int $status = 0, private bool $retryable = false)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
