<?php

namespace App\Exceptions;

/**
 * An online order cannot be confirmed (not pending any more, no warehouse,
 * stock ran out). The message is safe to show to the admin — unlike a
 * database error, which stays a 500.
 */
class OnlineOrderConfirmException extends \RuntimeException
{
}
