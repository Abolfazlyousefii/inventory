<?php

namespace App\Exceptions;

use RuntimeException;

class StockCountFinalizeValidationException extends RuntimeException
{
    public function __construct(string $message, private readonly array $conflicts = [])
    {
        parent::__construct($message, 422);
    }

    public function conflicts(): array
    {
        return $this->conflicts;
    }
}
