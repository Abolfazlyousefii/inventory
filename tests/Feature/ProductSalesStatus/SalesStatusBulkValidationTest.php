<?php

use App\Http\Controllers\ProductSalesStatusBulkController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusBulkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function validationBulkProduct(Category $category): Product
{
    return Product::query()->create(['category_id' => $category->id, 'name' => uniqid('کالا '), 'sku' => uniqid('VP-'), 'stock' => 0, 'price' => 1000, 'is_sellable' => true]);
}

it('rejects empty and over-limit product selections before any document or status mutation', function (): void {
    $service = app(ProductSalesStatusBulkService::class);
    expect(fn () => $service->resolveProductIds('multiple_products', null, null, []))->toThrow(ValidationException::class);
    expect(fn () => $service->resolveProductIds('multiple_products', null, null, range(1, ProductSalesStatusBulkService::MAX_PRODUCTS + 1)))->toThrow(ValidationException::class);
    expect(ProductDeactivationDocument::query()->count())->toBe(0);
});

it('rejects a subcategory paired with another parent category', function (): void {
    $first = Category::query()->create(['name' => 'والد اول', 'code' => uniqid('BC-')]);
    $second = Category::query()->create(['name' => 'والد دوم', 'code' => uniqid('BC-')]);
    $child = Category::query()->create(['name' => 'فرزند', 'code' => uniqid('BC-'), 'parent_id' => $first->id]);

    expect(fn () => app(ProductSalesStatusBulkService::class)->resolveProductIds('subcategory', $second->id, $child->id, []))
        ->toThrow(ValidationException::class, 'زیردسته انتخاب‌شده متعلق');
});

it('rejects missing confirmation and preview tokens at the execute boundary without mutation', function (): void {
    $category = Category::query()->create(['name' => 'دسته', 'code' => uniqid('BC-')]);
    $product = validationBulkProduct($category);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع', 'variant_code' => uniqid('BV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => true, 'sales_enabled' => true]);
    $request = Request::create('/bulk', 'POST', [
        'scope_type' => 'multiple_products',
        'action_type' => 'deactivate',
        'product_ids' => [$product->id],
        'reason_type' => 'management_decision',
    ]);
    $request->setUserResolver(fn () => User::factory()->create());

    expect(fn () => app(ProductSalesStatusBulkController::class)->store($request, app(ProductSalesStatusBulkService::class)))
        ->toThrow(ValidationException::class);
    expect($variant->fresh()->sales_enabled)->toBeTrue()
        ->and(ProductDeactivationDocument::query()->count())->toBe(0);
});

it('rejects a stale preview after another transaction changes one target', function (): void {
    $category = Category::query()->create(['name' => 'دسته', 'code' => uniqid('BC-')]);
    $product = validationBulkProduct($category);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع', 'variant_code' => uniqid('BV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => true, 'sales_enabled' => true]);
    $service = app(ProductSalesStatusBulkService::class);
    $preview = $service->preview([$product->id], 'deactivate', 'multiple_products');
    $variant->update(['sales_enabled' => false]);

    expect(fn () => $service->execute([$product->id], 'deactivate', 'multiple_products', 'management_decision', null, User::factory()->create(), $preview['preview_token']))
        ->toThrow(ValidationException::class, 'پیش‌نمایش منقضی شده است');
    expect(ProductDeactivationDocument::query()->count())->toBe(0)
        ->and($variant->fresh()->sales_enabled)->toBeFalse();
});
