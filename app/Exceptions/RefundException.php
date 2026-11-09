<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refund request that cannot proceed. The message is safe to show to the user.
 */
class RefundException extends RuntimeException
{
}
