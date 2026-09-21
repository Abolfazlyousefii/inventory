<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
