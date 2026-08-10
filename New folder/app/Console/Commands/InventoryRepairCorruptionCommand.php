<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCorruptionAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InventoryRepairCorruptionCommand extends Command
{
    protected $signature = 'inventory:repair-corruption
        {--product= : Repair one product id}
        {--all : Repair all products}
        {--dry-run : Show planned changes only (default)}
        {--apply : Apply changes}
        {--backup : Create backup tables before apply}
        {--force : Required with --apply}';

    protected $description = 'Safely repair inventory corruption from internal documents; dry-run by default.';

    private array $backupTables = [
        'product_variants',
        'warehouse_stocks',
        'products',
        'preinvoice_draft_reservations',
        'preinvoice_order_items',
        'preinvoice_orders',
    ];

    public function handle(InventoryCorruptionAuditService $audit): int
    {
        $productId = $this->option('product') !== null ? (int) $this->option('product') : null;
        $apply = (bool) $this->option('apply');

        if (! $productId && ! $this->option('all')) {
            $this->error('Use --product=<id> or --all. Default mode is dry-run.');
            return SymfonyCommand::FAILURE;
        }

        if ($apply && (! $this->option('backup') || ! $this->option('force'))) {
            $this->error('Refusing to apply. Use --apply --backup --force after reviewing dry-run output.');
            return SymfonyCommand::FAILURE;
        }

        $rows = $audit->rows($productId);
        $safeRows = $rows->filter(fn ($row) => (int) $row['expected_available_stock'] >= 0)->values();
        $conflicts = $rows->filter(fn ($row) => (int) $row['expected_available_stock'] < 0)->values();

        $this->info($apply ? 'APPLY mode requested.' : 'DRY-RUN mode: no data will be changed.');
        $this->table(['metric', 'value'], [
            ['variants_scanned', $rows->count()],
            ['variants_planned_for_update', $safeRows->count()],
            ['conflicts_skipped_negative_expected_stock', $conflicts->count()],
            ['no_purchase_positive_stock_to_zero', $safeRows->where('has_no_purchase_but_positive_stock', 'yes')->count()],
            ['unmapped_product_level_sale_qty_reported_only', $rows->unique('product_id')->sum('unmapped_product_level_sale_qty')],
        ]);

        if ($safeRows->isNotEmpty()) {
            $this->table([
                'product_id', 'variant_id', 'stock_before', 'stock_after', 'reserved_before', 'reserved_after', 'warehouse_before', 'warehouse_after', 'price_action', 'notes',
            ], $safeRows->map(function ($row) {
                $priceAction = $row['last_valid_purchase_item_id']
                    ? "set from purchase_item #{$row['last_valid_purchase_item_id']}"
                    : 'keep current (no valid purchase price)';

                return [
                    $row['product_id'],
                    $row['variant_id'],
                    $row['current_product_variant_stock'],
                    $row['expected_available_stock'],
                    $row['current_product_variant_reserved'],
                    $row['expected_reserved'],
                    $row['current_warehouse_stock'],
                    $row['expected_available_stock'],
                    $priceAction,
                    $row['notes'],
                ];
            })->all());
        }

        if ($conflicts->isNotEmpty()) {
            $this->warn('Conflicts skipped because expected_available_stock is negative:');
            $this->table(array_keys($conflicts->first()), $conflicts->all());
        }

        if (! $apply) {
            return SymfonyCommand::SUCCESS;
        }

        $centralWarehouseId = $audit->centralWarehouseId();
        if (! $centralWarehouseId) {
            $this->error('Central warehouse was not found. Repair aborted before updates.');
            return SymfonyCommand::FAILURE;
        }

        $backupNames = $this->createBackups();
        $this->info('Backup tables created: ' . implode(', ', $backupNames));

        DB::transaction(function () use ($audit, $productId, $safeRows, $centralWarehouseId) {
            $inactiveReservationIds = $audit->inactiveDraftReservationIds($productId);
            if ($inactiveReservationIds->isNotEmpty()) {
                DB::table('preinvoice_draft_reservations')
                    ->whereIn('id', $inactiveReservationIds->all())
                    ->update(['converted_at' => now(), 'updated_at' => now()]);
            }

            $productsTouched = [];
            foreach ($safeRows as $row) {
                $variantUpdate = [
                    'stock' => (int) $row['expected_available_stock'],
                    'reserved' => (int) $row['expected_reserved'],
                    'updated_at' => now(),
                ];

                if ($row['last_valid_purchase_item_id']) {
                    $variantUpdate['buy_price'] = (int) $row['expected_buy_price'];
                    $variantUpdate['sell_price'] = (int) $row['expected_sell_price'];
                }

                DB::table('product_variants')
                    ->where('id', (int) $row['variant_id'])
                    ->lockForUpdate()
                    ->update($variantUpdate);

                $this->setWarehouseStock(
                    $centralWarehouseId,
                    (int) $row['product_id'],
                    (int) $row['variant_id'],
                    (int) $row['expected_available_stock']
                );

                $productsTouched[(int) $row['product_id']] = true;
            }

            foreach (array_keys($productsTouched) as $touchedProductId) {
                $stockSum = (int) DB::table('product_variants')->where('product_id', $touchedProductId)->sum('stock');
                $reservedSum = (int) DB::table('product_variants')->where('product_id', $touchedProductId)->sum('reserved');
                $minSellPrice = DB::table('product_variants')
                    ->where('product_id', $touchedProductId)
                    ->where('sell_price', '>', 0)
                    ->min('sell_price');

                $this->setWarehouseStock(
                    $centralWarehouseId,
                    $touchedProductId,
                    null,
                    $stockSum
                );

                $productUpdate = [
                    'stock' => $stockSum,
                    'reserved' => $reservedSum,
                    'updated_at' => now(),
                ];
                if ($minSellPrice !== null) {
                    $productUpdate['price'] = (int) $minSellPrice;
                }

                DB::table('products')
                    ->where('id', $touchedProductId)
                    ->lockForUpdate()
                    ->update($productUpdate);
            }
        });

        $this->info('Repair completed. Unmapped product-level sales were reported only and were not deducted from any variant.');

        return SymfonyCommand::SUCCESS;
    }

    private function setWarehouseStock(int $warehouseId, int $productId, ?int $variantId, int $quantity): void
    {
        $query = DB::table('warehouse_stocks')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        $existing = $query->lockForUpdate()->first(['id']);

        if ($existing) {
            DB::table('warehouse_stocks')
                ->where('id', $existing->id)
                ->update(['quantity' => $quantity, 'updated_at' => now()]);

            return;
        }

        DB::table('warehouse_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBackups(): array
    {
        $timestamp = now()->format('Ymd_His');
        $backupNames = [];

        foreach ($this->backupTables as $table) {
            $backupName = "backup_{$table}_{$timestamp}";
            DB::statement("CREATE TABLE `{$backupName}` AS SELECT * FROM `{$table}`");
            $backupNames[] = $backupName;
        }

        return $backupNames;
    }
}
