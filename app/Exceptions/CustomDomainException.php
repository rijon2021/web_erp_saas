<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A custom-domain operation that the caller can act on (bad input, duplicate,
 * plan limit, not yet verified). Carries a stable reason code so each surface
 * (super-admin Blade flash, tenant JSON API) can pick its own wording.
 */
class CustomDomainException extends RuntimeException
{
    public function __construct(private readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
