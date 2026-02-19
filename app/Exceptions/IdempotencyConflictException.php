<?php

namespace App\Exceptions;

use Exception;

class IdempotencyConflictException extends Exception
{
    public function __construct(string $message = 'Idempotency key already used with different payload')
    {
        parent::__construct($message, 409);
    }
}
