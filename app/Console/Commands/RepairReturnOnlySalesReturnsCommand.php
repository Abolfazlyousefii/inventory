<?php

namespace App\Console\Commands;

use App\Models\CustomerLedger;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseInboundReceipt;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairReturnOnlySalesReturnsCommand extends Command
{
    protected $signature = 'sales-returns:repair-return-only
        {--documents=151,152,154,155,157 : Comma separated sales return document numbers}
        {--actor=119 : User id that will be written as applied_by}
        {--dry-run : Only report what would happen without changing data}';

    protected $description = 'Safely finalize old sales-return documents that only target the return warehouse and have no inbound receipt.';

    public function __construct(private readonly WarehouseInboundService $warehouseInbound)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $documentNumbers = collect(explode(',', (string) $this->option('documents')))
            ->map(fn ($number) => trim($number))
            ->filter()
            ->unique()
            ->values();

        $actorId = (int) $this->option('actor');
        $dryRun = (bool) $this->option('dry-run');
        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        if ($documentNumbers->isEmpty()) {
            $this->error('No document numbers were provided.');
            return self::FAILURE;
        }

        if ($actorId <= 0) {
            $this->error('Actor id is required and must be a positive integer.');
            return self::FAILURE;
        }

        $this->info($dryRun ? 'DRY RUN: no database rows will be changed.' : 'LIVE RUN: eligible documents will be finalized.');
        $this->line('Documents: ' . $documentNumbers->implode(', '));
        $this->line('Actor: ' . $actorId);
        $this->line('Central warehouse id: ' . $centralWarehouseId);
        $this->newLine();

        $rows = [];
        $changed = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($documentNumbers as $documentNumber) {
            /** @var SalesReturnDocument|null $document */
            $document = SalesReturnDocument::query()
                ->with('items')
                ->where('document_number', $documentNumber)
                ->first();

            if (! $document) {
                $missing++;
                $rows[] = [$documentNumber, '-', '-', '-', '-', '-', 'MISSING', 'Document not found'];
                continue;
            }

            $check = $this->checkEligibility($document, $centralWarehouseId);

            if (! $check['eligible']) {
                $skipped++;
                $rows[] = [
                    $document->document_number,
                    $document->id,
                    $document->status,
                    $check['destinations'],
                    $check['receipt_count'],
                    $check['ledger_count'],
                    'SKIPPED',
                    $check['reason'],
                ];
                continue;
            }

            if ($dryRun) {
                $rows[] = [
                    $document->document_number,
                    $document->id,
                    $document->status,
                    $check['destinations'],
                    $check['receipt_count'],
                    $check['ledger_count'],
                    'READY',
                    'Eligible for finalize without inbound',
                ];
                continue;
            }

            try {
                $this->warehouseInbound->finalizeSalesReturnWithoutInbound($document, $actorId);

                $fresh = SalesReturnDocument::query()->find($document->id);
                $ledgerCount = CustomerLedger::query()
                    ->where('reference_type', SalesReturnDocument::class)
                    ->where('reference_id', $document->id)
                    ->where('type', 'credit')
                    ->count();

                $changed++;
                $rows[] = [
                    $fresh?->document_number ?? $document->document_number,
                    $document->id,
                    $fresh?->status ?? '-',
                    $check['destinations'],
                    $check['receipt_count'],
                    $ledgerCount,
                    'FINALIZED',
                    'Applied without inbound successfully',
                ];
            } catch (\Throwable $e) {
                $skipped++;
                $rows[] = [
                    $document->document_number,
                    $document->id,
                    $document->status,
                    $check['destinations'],
                    $check['receipt_count'],
                    $check['ledger_count'],
                    'ERROR',
                    $e->getMessage(),
                ];
            }
        }

        $this->table(
            ['Document', 'ID', 'Status', 'Destinations', 'Receipts', 'Ledgers', 'Result', 'Message'],
            $rows
        );

        $this->newLine();
        $this->info("Changed: {$changed} | Skipped: {$skipped} | Missing: {$missing}");

        return self::SUCCESS;
    }

    private function checkEligibility(SalesReturnDocument $document, int $centralWarehouseId): array
    {
        $document->loadMissing('items');

        $destinations = $document->items
            ->pluck('destination_warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        $receiptCount = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_SALES_RETURN)
            ->where('source_id', $document->id)
            ->count();

        $ledgerCount = CustomerLedger::query()
            ->where('reference_type', SalesReturnDocument::class)
            ->where('reference_id', $document->id)
            ->where('type', 'credit')
            ->count();

        $summary = [
            'destinations' => $destinations->implode(','),
            'receipt_count' => $receiptCount,
            'ledger_count' => $ledgerCount,
        ];

        if ($document->status !== SalesReturnDocument::STATUS_PENDING_WAREHOUSE) {
            return $summary + [
                'eligible' => false,
                'reason' => 'Status is not pending_warehouse',
            ];
        }

        if ($document->items->isEmpty()) {
            return $summary + [
                'eligible' => false,
                'reason' => 'Document has no items',
            ];
        }

        if ($destinations->contains($centralWarehouseId)) {
            return $summary + [
                'eligible' => false,
                'reason' => 'Document contains central warehouse items',
            ];
        }

        if ($receiptCount > 0) {
            return $summary + [
                'eligible' => false,
                'reason' => 'Document already has inbound receipt',
            ];
        }

        if ($ledgerCount > 0) {
            return $summary + [
                'eligible' => false,
                'reason' => 'Document already has customer ledger credit',
            ];
        }

        return $summary + [
            'eligible' => true,
            'reason' => 'OK',
        ];
    }
}
