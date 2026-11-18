<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteStockHold;
use App\Models\WarehouseStock;
use App\Services\SiteOrderStockHoldService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression coverage for physical warehouse stock holds during stocktake.
 * No payment, commission, accounting, UI, or route behavior is changed here.
 */
class SiteStockHoldStocktakeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config([
            'services.external_sync.token' => 'stocktake-regression-test-token',
            'services.request_driven_site_stock' => false,
        ]);
        $this->ensureHoldTables();
    }

    public function test_expired_hold_retry_during_later_stocktake_does_not_restore_stock_twice(): void
    {
        [$product, $variant, $warehouseId] = $this->catalog(11);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(99101, [
            ['variant_id' => (int) $variant->id, 'quantity' => 4],
        ], 1);

        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));
        $service->release(99101, (int) $hold->id, expired: true);
        $this->assertSame('expired', $hold->fresh()->status);
        $this->assertSame(11, $this->quantity($warehouseId, (int) $variant->id));
        $firstReturnTime = DB::table('site_stock_holds')->where('id', $hold->id)->value('stock_returned_at');
        $this->assertNotNull($firstReturnTime);

        $this->freezeProduct((int) $product->id);
        // A delayed retry, regardless of its `expired` argument, is a read-only no-op.
        $result = $service->release(99101, (int) $hold->id);
        $this->assertSame('expired', $result->status);
        $this->assertSame(11, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertSame($firstReturnTime, DB::table('site_stock_holds')
            ->where('id', $hold->id)->value('stock_returned_at'));
    }

    public function test_stocktake_still_blocks_first_release_of_an_active_hold(): void
    {
        [$product, $variant, $warehouseId] = $this->catalog(10);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(99102, [
            ['variant_id' => (int) $variant->id, 'quantity' => 3],
        ], 60);
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));

        // Simulates stocktake becoming active between a stock hold and a release.
        $this->freezeProduct((int) $product->id);
        try {
            $service->release(99102, (int) $hold->id);
            $this->fail('A first-time stock change must still be blocked during stocktake.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stocktake', $exception->errors());
        }
        $this->assertSame('held', $hold->fresh()->status);
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertNull(DB::table('site_stock_holds')->where('id', $hold->id)->value('stock_returned_at'));

        DB::table('inventory_count_freezes')->where('id', 1)->update([
            'enabled' => false,
            'updated_at' => now(),
        ]);
        $service->release(99102, (int) $hold->id);
        $this->assertSame('released', $hold->fresh()->status);
        $this->assertSame(10, $this->quantity($warehouseId, (int) $variant->id));
    }

    private function freezeProduct(int $productId): void
    {
        DB::table('inventory_count_freezes')->updateOrInsert(['id' => 1], [
            'enabled' => true,
            'all_products' => false,
            'product_ids' => json_encode([$productId]),
            'category_ids' => '[]',
            'updated_at' => now(),
        ]);
    }

    /** @return array{Product, ProductVariant, int} */
    private function catalog(int $quantity): array
    {
        $category = Category::query()->create(['name' => 'Stocktake regression']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Stocktake physical item',
            'sku' => 'STOCK-RETRY-SKU',
            'price' => 1000,
            'stock' => $quantity,
            'is_sellable' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Default',
            'variant_code' => 'STOCK-RETRY-V',
            'sell_price' => 1000,
            'stock' => $quantity,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
        $warehouseId = WarehouseStockService::centralWarehouseId();
        WarehouseStock::query()->create([
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);

        return [$product, $variant, $warehouseId];
    }

    private function quantity(int $warehouseId, int $variantId): int
    {
        return (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->value('quantity');
    }

    private function ensureHoldTables(): void
    {
        // Same isolated SQLite test setup as SiteStockHoldEventIntegrationTest.
        if (! Schema::hasTable('site_stock_holds')) {
            Schema::create('site_stock_holds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_order_id')->unique();
                $table->string('fingerprint', 64);
                $table->string('status', 32);
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->json('bundle_allocated_lines')->nullable();
                $table->json('bundle_shared_allocations')->nullable();
                $table->timestamp('stock_applied_at')->nullable();
                $table->timestamp('stock_returned_at')->nullable();
                $table->timestamps();
            });
        }
        foreach (['bundle_allocated_lines', 'bundle_shared_allocations', 'stock_applied_at', 'stock_returned_at'] as $column) {
            if (! Schema::hasColumn('site_stock_holds', $column)) {
                Schema::table('site_stock_holds', function (Blueprint $table) use ($column): void {
                    if (str_ends_with($column, '_at')) {
                        $table->timestamp($column)->nullable();
                    } else {
                        $table->json($column)->nullable();
                    }
                });
            }
        }
        if (! Schema::hasTable('site_stock_hold_lines')) {
            Schema::create('site_stock_hold_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('hold_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id');
                $table->unsignedInteger('quantity');
            });
        }
        if (! Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('quantity');
            });
        }
        if (! Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('remaining');
                $table->timestamps();
            });
        }
    }
}
