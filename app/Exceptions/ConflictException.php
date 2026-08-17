<?php

namespace App\Exceptions;

class ConflictException extends DomainException
{
    public function __construct(string $message, string $code, ?array $details = null)
    {
        parent::__construct($message, 409, $code, $details);
    }
}
