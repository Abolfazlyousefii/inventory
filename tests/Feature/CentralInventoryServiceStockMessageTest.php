<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CentralInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CentralInventoryServiceStockMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_insufficient_stock_message_identifies_the_exact_product_and_variant(): void
    {
        $category = Category::withoutEvents(fn () => Category::query()->create([
            'name' => 'Stock message '.Str::uuid(),
        ]));

        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'کابل یوشیتا X1',
            'code' => '4450',
            'sku' => 'YS-X1',
            'stock' => 0,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));

        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'مشکی',
            'variant_code' => 'YS-X1-BLK',
            'stock' => 0,
            'reserved' => 0,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ]));

        try {
            app(CentralInventoryService::class)->assertVariantAvailable($variant->id, 6);
            $this->fail('Expected insufficient stock validation exception was not thrown.');
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors()['products'] ?? [])->first();

            $this->assertStringContainsString('کابل یوشیتا X1', $message);
            $this->assertStringContainsString('4450', $message);
            $this->assertStringContainsString('مشکی', $message);
            $this->assertStringContainsString('YS-X1-BLK', $message);
            $this->assertStringContainsString('موجودی: 0', $message);
            $this->assertStringContainsString('درخواست: 6', $message);
        }
    }
}
