<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalance extends RuntimeException
{
    public function __construct(public readonly float $balance, public readonly float $required)
    {
        parent::__construct('The wallet balance is too low for this operation.');
    }
}
