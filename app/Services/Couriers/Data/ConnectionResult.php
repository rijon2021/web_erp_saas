<?php

namespace App\Services\Couriers\Data;

/** Outcome of a credential check. */
final class ConnectionResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
    ) {
    }

    public static function ok(string $message = 'Connection successful.'): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
