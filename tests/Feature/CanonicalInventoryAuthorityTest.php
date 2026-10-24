<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Warehouse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CanonicalInventoryAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_read_returns_zero_for_a_missing_row_without_creating_it(): void
    {
        [$product, $variant] = $this->productAndVariant();
        $warehouseId = WarehouseStockService::centralWarehouseId();
        $before = WarehouseStock::query()->count();

        $this->assertSame(0, WarehouseStockService::available($warehouseId, $product->id, $variant->id));
        $this->assertSame($before, WarehouseStock::query()->count());
    }

    public function test_central_mutations_own_variant_and_product_stock_projections(): void
    {
        [$product, $first] = $this->productAndVariant();
        $second = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Second',
            'variant_code' => 'AUTH-'.Str::uuid(),
            'stock' => 999,
            'reserved' => 0,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
        $central = WarehouseStockService::centralWarehouseId();

        WarehouseStockService::change($central, $product->id, 8, $first->id);
        WarehouseStockService::change($central, $product->id, 5, $second->id);

        $this->assertSame(8, (int) $first->fresh()->stock);
        $this->assertSame(5, (int) $second->fresh()->stock);
        $this->assertSame(13, (int) $product->fresh()->stock);
        $this->assertSame(8, WarehouseStockService::available($central, $product->id, $first->id));
    }

    public function test_negative_canonical_mutation_rolls_back_without_projection_drift(): void
    {
        [$product, $variant] = $this->productAndVariant();
        $central = WarehouseStockService::centralWarehouseId();
        WarehouseStockService::change($central, $product->id, 3, $variant->id);

        try {
            WarehouseStockService::change($central, $product->id, -4, $variant->id);
            $this->fail('Expected insufficient-stock failure.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(3, WarehouseStockService::available($central, $product->id, $variant->id));
        $this->assertSame(3, (int) $variant->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    public function test_mixed_stock_changes_preserve_all_balances_after_every_step(): void
    {
        Http::fake();
        [$product, $first] = $this->productAndVariant();
        $second = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Second',
            'variant_code' => 'AUTH-'.Str::uuid(), 'stock' => 0, 'reserved' => 0,
            'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true,
        ]));
        $central = WarehouseStockService::centralWarehouseId();
        $returns = Warehouse::query()->create(['name' => 'Returns audit', 'type' => 'return', 'is_active' => true])->id;
        $variants = [$first->id, $second->id];
        $expected = [];
        foreach ([$central, $returns] as $warehouse) {
            foreach ($variants as $variant) {
                WarehouseStockService::set($warehouse, $product->id, $variant, 20);
                $expected[$warehouse][$variant] = 20;
            }
        }

        // A fixed sequence is repeatable; it covers mixed warehouses/variants,
        // insufficient stock, set operations and failures later in a document.
        $seed = 1949;
        for ($step = 0; $step < 120; $step++) {
            $seed = ($seed * 48271) % 2147483647;
            $warehouse = $step % 3 === 0 ? $returns : $central;
            $variant = $variants[$seed % 2];
            $delta = ($seed % 31) - 15;
            $before = $expected[$warehouse][$variant];
            $rollback = $step % 7 === 0;
            try {
                DB::transaction(function () use ($warehouse, $product, $variant, $delta, $rollback) {
                    WarehouseStockService::change($warehouse, $product->id, $delta, $variant);
                    if ($rollback) throw new \RuntimeException('Simulated document failure');
                });
                $this->assertGreaterThanOrEqual(0, $before + $delta);
                $expected[$warehouse][$variant] += $delta;
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
                $this->assertLessThan(0, $before + $delta);
            } catch (\RuntimeException $exception) {
                $this->assertTrue($rollback);
                $this->assertSame('Simulated document failure', $exception->getMessage());
            }
            foreach ($expected as $warehouseId => $balances) {
                foreach ($balances as $variantId => $quantity) {
                    $this->assertSame($quantity, WarehouseStockService::available($warehouseId, $product->id, $variantId), "Step {$step}");
                }
            }
            foreach ($variants as $variantId) {
                $this->assertSame($expected[$central][$variantId], (int) ProductVariant::findOrFail($variantId)->stock);
            }
            $this->assertSame(array_sum($expected[$central]), (int) $product->fresh()->stock);
        }
    }

    public function test_commercial_structure_refresh_cannot_replace_inventory_balances(): void
    {
        Http::fake();
        [$product, $variant] = $this->productAndVariant();
        WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id, 7);
        // This legacy row does not match today's commercial design structure.
        $product->forceFill(['models' => ['use_models' => false, 'use_designs' => true, 'design_count' => 1], 'reserved' => 3])->save();
        app(\App\Services\ProductVariantStructureService::class)->recalculateProductSummary($product->fresh());
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->reserved);
        $this->assertSame(7, WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id));
    }

    /** @return array{Product, ProductVariant} */
    private function productAndVariant(): array
    {
        $category = Category::query()->create(['name' => 'Authority '.Str::uuid()]);
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Authority product',
            'sku' => 'AUTH-'.Str::uuid(),
            'stock' => 777,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'First',
            'variant_code' => 'AUTH-'.Str::uuid(),
            'stock' => 777,
            'reserved' => 0,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ]);

        return [$product, $variant];
    }
}
