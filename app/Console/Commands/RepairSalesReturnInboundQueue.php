<?php

namespace App\Console\Commands;

use App\Models\SalesReturnDocument;
use App\Models\WarehouseInboundReceipt;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairSalesReturnInboundQueue extends Command
{
    protected $signature = 'warehouse:repair-sales-return-inbound {--dry-run : Report changes without writing} {--apply : Repair pending receipts}';

    protected $description = 'Repair pending sales-return inbound receipts so they contain central-warehouse items only';

    public function handle(WarehouseInboundService $inbound): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Specify exactly one of --dry-run or --apply.');

            return self::INVALID;
        }

        $centralWarehouseId = WarehouseStockService::centralWarehouseId();
        $receipts = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_SALES_RETURN)
            ->where('status', WarehouseInboundReceipt::STATUS_PENDING)
            ->with('items')
            ->orderBy('id')
            ->get();

        $changed = 0;
        foreach ($receipts as $receipt) {
            $document = SalesReturnDocument::query()->with(['items.product', 'items.variant', 'invoice:id,customer_name'])->find($receipt->source_id);
            if (! $document) {
                $this->warn("Receipt {$receipt->id}: source document {$receipt->source_id} is missing; skipped.");
                continue;
            }

            $desiredIds = $document->items
                ->where('destination_warehouse_id', $centralWarehouseId)
                ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            $currentIds = $receipt->items->pluck('source_item_id')->map(fn ($id) => (int) $id)->sort()->values();
            $desiredQuantity = (int) $document->items
                ->where('destination_warehouse_id', $centralWarehouseId)
                ->sum('return_quantity');
            $removeIds = $currentIds->diff($desiredIds)->values();
            $addIds = $desiredIds->diff($currentIds)->values();

            if ($desiredIds->all() === $currentIds->all() && $desiredQuantity === (int) $receipt->expected_quantity) {
                continue;
            }

            $changed++;
            $this->line("Receipt {$receipt->id} / sales return {$document->id}: items {$currentIds->count()} -> {$desiredIds->count()}, expected {$receipt->expected_quantity} -> {$desiredQuantity}; remove source_item_ids [{$removeIds->implode(',')}], add source_item_ids [{$addIds->implode(',')}]");

            if ($this->option('apply')) {
                DB::transaction(fn () => $inbound->queueSalesReturn($document, null, (string) $receipt->operation_key));
            }
        }

        $mode = $this->option('apply') ? 'repaired' : 'would be repaired';
        $this->info("{$changed} pending receipt(s) {$mode}; finalized receipts were not touched.");

        return self::SUCCESS;
    }
}
