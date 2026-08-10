<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\WarehouseLocation;
use App\Models\WarehouseLocationStock;
use App\Models\WarehouseStock;
use App\Services\WarehouseMapService;
use App\Services\WarehouseStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateProductWarehouseLocationsCommand extends Command
{
    protected $signature = 'warehouse-map:migrate-product-locations {--dry-run : فقط گزارش بگیر و دیتابیس را تغییر نده}';
    protected $description = 'Migrate old product-level warehouse map fields into variant-level warehouse locations.';

    public function handle(WarehouseMapService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $warehouseId = WarehouseStockService::centralWarehouseId();
        $checked = $migrated = $skipped = 0;
        $warnings = [];

        Product::query()
            ->whereNotNull('warehouse_zone')
            ->with('variants')
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$checked, &$migrated, &$skipped, &$warnings, $warehouseId, $dryRun, $service) {
                foreach ($products as $product) {
                    $checked++;
                    $zone = 'Z'.str_pad((string) (int) $product->warehouse_zone, 2, '0', STR_PAD_LEFT);
                    $rows = collect((array) $product->warehouse_rows)->filter()->values();
                    $bins = collect((array) $product->warehouse_bins)->filter()->values();
                    if ($rows->isEmpty() || $bins->isEmpty() || $product->variants->isEmpty()) {
                        $skipped += max(1, $product->variants->count());
                        $warnings[] = "Product {$product->id}: نقشه قدیمی یا تنوع کافی نیست.";
                        continue;
                    }
                    $rack = 'R'.str_pad((string) (int) $rows->first(), 2, '0', STR_PAD_LEFT);
                    $box = 'B'.str_pad((string) (int) $bins->first(), 2, '0', STR_PAD_LEFT);

                    foreach ($product->variants as $variant) {
                        if (WarehouseLocationStock::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variant->id)->exists()) {
                            $skipped++;
                            continue;
                        }
                        $stock = WarehouseStock::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variant->id)->value('quantity');
                        $qty = $stock !== null ? max(0, (int) $stock) : max(0, (int) $variant->stock);
                        if ($dryRun) {
                            $this->line("DRY: variant {$variant->id} => {$zone}-{$rack}-{$box}, qty={$qty}");
                            $migrated++;
                            continue;
                        }
                        DB::transaction(function () use ($warehouseId, $zone, $rack, $box, $variant, $qty, $service) {
                            $location = WarehouseLocation::firstOrCreate([
                                'warehouse_id' => $warehouseId, 'zone' => $zone, 'rack' => $rack, 'box' => $box,
                            ], ['code' => WarehouseLocation::makeCode($zone, $rack, $box), 'is_active' => true]);
                            if ($qty > 0) {
                                $service->assignLocation($variant->id, $warehouseId, $location->id, $qty, null, 'انتقال امن نقشه قدیمی محصول به تنوع');
                            } else {
                                WarehouseLocationStock::firstOrCreate(['warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id, 'warehouse_location_id' => $location->id], ['quantity' => 0]);
                            }
                        });
                        $migrated++;
                    }
                }
            });

        $this->info("Checked products: {$checked}");
        $this->info("Migrated variants: {$migrated}");
        $this->info("Skipped variants/items: {$skipped}");
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        return self::SUCCESS;
    }
}
