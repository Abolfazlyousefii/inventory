<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Sync\SyncProductsToSite;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncProductsToSiteStockScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_stock_uses_only_central_warehouse_and_excludes_damaged_returns(): void
    {
        $category = Category::create(['name' => 'Sync stock scope', 'code' => '98']);
        $product = Product::withoutEvents(fn () => Product::create([
            'name' => 'Central stock product',
            'category_id' => $category->id,
            'code' => '980001',
            'sku' => 'SYNC-STOCK-SCOPE',
            'is_sellable' => true,
            'price' => 1000,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $product->id,
            'variant_name' => 'Test variant',
            'variant_code' => 'SYNC-STOCK-SCOPE-1',
            'variety_code' => '0001',
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
            'stock' => 9,
        ]));

        $centralId = WarehouseStockService::centralWarehouseId();
        $returnWarehouse = Warehouse::create([
            'name' => 'انبار مرجوعی',
            'type' => 'return',
            'is_active' => true,
        ]);

        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id' => $centralId,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id' => $returnWarehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 7,
        ]));

        $method = new \ReflectionMethod(SyncProductsToSite::class, 'stockRowsForVariants');
        $rows = $method->invoke(new SyncProductsToSite(), [$variant->id]);

        $this->assertSame(2, (int) $rows->get($variant->id)->total_stock);
    }
}
