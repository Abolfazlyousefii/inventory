<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCorruptionAuditService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InventoryRepairCorruptionCommand extends Command
{
    protected $signature = 'inventory:repair-corruption
        {--product= : Report one product id}
        {--all : Report all products}
        {--dry-run : Show diagnostic estimates only (default)}
        {--apply : Disabled; apply requests are always rejected}
        {--backup : Legacy compatibility option; never creates backups}
        {--force : Legacy compatibility option; cannot enable apply}';

    protected $description = 'Read-only legacy inventory diagnostics; estimates are not authoritative and apply is disabled.';

    public function handle(InventoryCorruptionAuditService $audit): int
    {
        if ((bool) $this->option('apply')) {
            $this->error('APPLY mode is disabled: the legacy formula is not authoritative for current inventory flows.');
            $this->line('Make inventory corrections through traceable adjustment documents with a reason and responsible user.');
            return SymfonyCommand::FAILURE;
        }

        $productId = $this->option('product') !== null ? (int) $this->option('product') : null;
        if (! $productId && ! $this->option('all')) {
            $this->error('Use --product=<id> or --all. Diagnostic mode only.');
            return SymfonyCommand::FAILURE;
        }

        $this->warn('DIAGNOSTIC ONLY: legacy estimates are not authoritative and must not be used for automatic corrections.');
        $this->line('Adjustment and return flows are not fully covered; non-negative estimates are not proof of correctness.');

        $rows = $audit->rows($productId);
        $nonNegativeRows = $rows->filter(fn ($row) => (int) $row['expected_available_stock'] >= 0)->values();
        $conflicts = $rows->filter(fn ($row) => (int) $row['expected_available_stock'] < 0)->values();

        $this->info('READ-ONLY: no data will be changed.');
        $this->table(['metric', 'value'], [
            ['variants_scanned', $rows->count()],
            ['variants_with_nonnegative_legacy_estimate', $nonNegativeRows->count()],
            ['variants_with_negative_legacy_estimate', $conflicts->count()],
            ['positive_stock_without_purchase_history', $nonNegativeRows->where('has_no_purchase_but_positive_stock', 'yes')->count()],
            ['unmapped_product_level_sale_qty_reported_only', $rows->unique('product_id')->sum('unmapped_product_level_sale_qty')],
        ]);

        if ($nonNegativeRows->isNotEmpty()) {
            $this->table([
                'product_id', 'variant_id', 'stock_current', 'stock_legacy_estimate', 'reserved_current', 'reserved_legacy_estimate', 'warehouse_current', 'warehouse_legacy_estimate', 'historical_price_reference', 'notes',
            ], $nonNegativeRows->map(function ($row) {
                $priceReference = $row['last_valid_purchase_item_id']
                    ? "historical purchase_item #{$row['last_valid_purchase_item_id']}"
                    : 'no historical purchase price reference';

                return [
                    $row['product_id'],
                    $row['variant_id'],
                    $row['current_product_variant_stock'],
                    $row['expected_available_stock'],
                    $row['current_product_variant_reserved'],
                    $row['expected_reserved'],
                    $row['current_warehouse_stock'],
                    $row['expected_available_stock'],
                    $priceReference,
                    $row['notes'],
                ];
            })->all());
        }

        if ($conflicts->isNotEmpty()) {
            $this->warn('Negative legacy estimates (diagnostic only):');
            $this->table(array_keys($conflicts->first()), $conflicts->all());
        }

        return SymfonyCommand::SUCCESS;
    }
}
