<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceChangeAuditRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }
}
