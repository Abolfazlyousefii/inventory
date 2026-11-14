<?php

namespace App\Console\Commands;

use App\Services\ReturnsIntegrityAuditService;
use Illuminate\Console\Command;

class AuditReturnsIntegrity extends Command
{
    protected $signature = 'returns:audit {--dry-run} {--sales-return=432} {--purchase-return=00004} {--json}';
    protected $description = 'Read-only return documents, warehouse receipts, movement and financial audit. No repair option.';

    public function handle(ReturnsIntegrityAuditService $audit): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Only --dry-run is supported. Physical stock confirmation and explicit approval are required before a separate repair.');
            return self::FAILURE;
        }
        $report = $audit->report((string) $this->option('sales-return'), (string) $this->option('purchase-return'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
