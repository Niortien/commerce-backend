<?php

namespace App\Exceptions;

class NotFoundException extends DomainException
{
    public function __construct(string $message, string $code, ?array $details = null)
    {
        parent::__construct($message, 404, $code, $details);
    }
}
