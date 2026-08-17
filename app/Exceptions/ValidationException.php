<?php

namespace App\Exceptions;

class ValidationException extends DomainException
{
    public function __construct(string $message, string $code, ?array $details = null)
    {
        parent::__construct($message, 422, $code, $details);
    }
}
