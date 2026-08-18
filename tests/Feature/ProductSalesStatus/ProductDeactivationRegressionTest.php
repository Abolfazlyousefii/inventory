<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('product deactivation changes only commercial eligibility and creates exact history items', function (): void {
    $category = Category::query()->create(['name' => 'غیرفعال‌سازی', 'code' => uniqid('DC-')]);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'کالا', 'sku' => uniqid('DP-'), 'stock' => 9, 'reserved' => 3, 'price' => 1000, 'is_sellable' => true]);
    $eligible = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'مجاز', 'variant_code' => uniqid('DV-'), 'sell_price' => 1000, 'stock' => 9, 'reserved' => 3, 'is_active' => true, 'sales_enabled' => true]);
    $structural = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'ساختاری', 'variant_code' => uniqid('DV-'), 'sell_price' => 1000, 'stock' => 7, 'reserved' => 2, 'is_active' => false, 'sales_enabled' => false]);

    $document = app(ProductSalesStatusService::class)->change($product->id, 'deactivate', 'product', [], 'management_decision', null, User::factory()->create());

    expect($product->fresh()->is_sellable)->toBeFalse()
        ->and($eligible->fresh()->sales_enabled)->toBeFalse()
        ->and($eligible->fresh()->is_active)->toBeTrue()
        ->and($structural->fresh()->is_active)->toBeFalse()
        ->and($structural->fresh()->sales_enabled)->toBeFalse()
        ->and([$product->fresh()->stock, $product->fresh()->reserved, $eligible->fresh()->stock, $eligible->fresh()->reserved])->toBe([9, 3, 9, 3])
        ->and($document->action_type)->toBe('deactivate')
        ->and($document->scope_type)->toBe('product')
        ->and($document->items_count)->toBe(1)
        ->and($document->items()->count())->toBe(1);

    expect(fn () => app(ProductSalesStatusService::class)->change($product->id, 'deactivate', 'product', [], 'management_decision', null, User::factory()->create()))
        ->toThrow(ValidationException::class);
    expect(ProductDeactivationDocument::query()->count())->toBe(1);
});
