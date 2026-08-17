<?php

namespace App\Exceptions;

use Exception;

class DomainException extends Exception
{
    public readonly string $errorCode;

    public function __construct(
        string $message,
        public readonly int $statusCode,
        string $code,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
        $this->errorCode = $code;
    }
}

