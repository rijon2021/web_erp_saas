<?php

namespace App\Services\Couriers;

use RuntimeException;

/**
 * A courier call failed in a way the user can act on (bad credentials, an
 * unknown city, an unsupported action). The message is shown as-is.
 */
class CourierException extends RuntimeException
{
    public static function unsupported(string $courier, string $action): self
    {
        return new self("{$courier} does not support {$action}.");
    }
}
