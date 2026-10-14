<?php

namespace App\Events;

final class CustomerOrderStatusChanged
{
    public function __construct(
        public readonly string $type,
        public readonly int $documentId,
        public readonly int $customerId,
        public readonly string $status,
    ) {}
}
