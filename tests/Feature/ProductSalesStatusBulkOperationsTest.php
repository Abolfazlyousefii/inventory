<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusBulkService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function bulkCategory(string $name, ?Category $parent = null): Category
{
    return Category::query()->create(['name' => $name, 'code' => uniqid('C-'), 'parent_id' => $parent?->id]);
}

function bulkProduct(Category $category, string $name): Product
{
    return Product::query()->create(['category_id' => $category->id, 'name' => $name, 'sku' => uniqid('BP-'), 'stock' => 0, 'price' => 1000, 'is_sellable' => true]);
}

function bulkVariant(Product $product, string $name, bool $active = true, bool $enabled = true): ProductVariant
{
    return ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => $name, 'variant_code' => uniqid('BV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => $active, 'sales_enabled' => $enabled]);
}

function bulkExecute(ProductSalesStatusBulkService $service, array $ids, string $action, string $scope = 'multiple_products'): ProductDeactivationDocument
{
    $preview = $service->preview($ids, $action, $scope);

    return $service->execute($ids, $action, $scope, $action === 'activate' ? 'restocked' : 'management_decision', null, User::factory()->create(), $preview['preview_token']);
}

it('previews a category recursively without mutating data', function (): void {
    $root = bulkCategory('ریشه');
    $child = bulkCategory('فرزند', $root);
    $deep = bulkCategory('عمق سوم', $child);
    $other = bulkCategory('نامرتبط');
    $products = [bulkProduct($root, 'یک'), bulkProduct($child, 'دو'), bulkProduct($deep, 'سه')];
    bulkProduct($other, 'خارج');
    bulkVariant($products[0], 'فعال');
    bulkVariant($products[1], 'قبلاً خاموش', true, false);
    bulkVariant($products[2], 'ساختاری خاموش', false, true);
    $service = app(ProductSalesStatusBulkService::class);

    $ids = $service->resolveProductIds('category', $root->id, null, []);
    $preview = $service->preview($ids, 'deactivate', 'category');

    expect($ids)->toBe(collect($products)->pluck('id')->sort()->values()->all())
        ->and($preview['products_count'])->toBe(3)
        ->and($preview['structurally_active_variants'])->toBe(2)
        ->and($preview['effective_changes'])->toBe(1)
        ->and($preview['already_desired'])->toBe(1)
        ->and($preview['unable_to_change'])->toBe(1)
        ->and(ProductDeactivationDocument::query()->count())->toBe(0)
        ->and(ProductVariant::query()->where('sales_enabled', false)->count())->toBe(1);
});

it('resolves only the exact subcategory and excludes siblings and descendants', function (): void {
    $root = bulkCategory('ریشه');
    $target = bulkCategory('هدف', $root);
    $sibling = bulkCategory('هم‌سطح', $root);
    $deeper = bulkCategory('زیرتر', $target);
    $inside = bulkProduct($target, 'داخل');
    bulkProduct($sibling, 'هم‌سطح');
    bulkProduct($deeper, 'عمق بعدی');

    $ids = app(ProductSalesStatusBulkService::class)->resolveProductIds('subcategory', $root->id, $target->id, []);
    expect($ids)->toBe([$inside->id]);
});

it('deduplicates multiple products and rejects invalid ids', function (): void {
    $category = bulkCategory('دسته');
    $first = bulkProduct($category, 'اول');
    $second = bulkProduct($category, 'دوم');
    $service = app(ProductSalesStatusBulkService::class);

    expect($service->resolveProductIds('multiple_products', null, null, [$second->id, $first->id, $first->id]))->toBe([$first->id, $second->id]);
    $service->resolveProductIds('multiple_products', null, null, [$first->id, 999999]);
})->throws(ValidationException::class, 'یک یا چند کالای انتخاب‌شده معتبر نیست');

it('executes bulk deactivation atomically with one document and effective items only', function (): void {
    $category = bulkCategory('دسته');
    $first = bulkProduct($category, 'اول');
    $second = bulkProduct($category, 'دوم');
    $changeA = bulkVariant($first, 'A');
    $already = bulkVariant($first, 'B', true, false);
    $structural = bulkVariant($second, 'C', false, true);
    $changeD = bulkVariant($second, 'D');

    $document = bulkExecute(app(ProductSalesStatusBulkService::class), [$second->id, $first->id], 'deactivate');

    expect($document->items)->toHaveCount(2)
        ->and(ProductDeactivationDocument::query()->count())->toBe(1)
        ->and($changeA->fresh()->sales_enabled)->toBeFalse()
        ->and($changeD->fresh()->sales_enabled)->toBeFalse()
        ->and($already->fresh()->sales_enabled)->toBeFalse()
        ->and($structural->fresh()->sales_enabled)->toBeTrue()
        ->and($structural->fresh()->is_active)->toBeFalse()
        ->and($first->fresh()->is_sellable)->toBeFalse()
        ->and($second->fresh()->is_sellable)->toBeFalse()
        ->and($document->items->every(fn ($item) => $item->previous_sales_enabled && ! $item->new_sales_enabled))->toBeTrue();
});

it('bulk activates only structurally valid variants disabled by a product level event', function (): void {
    $category = bulkCategory('دسته');
    $product = bulkProduct($category, 'کالا');
    $valid = bulkVariant($product, 'مجاز');
    $structural = bulkVariant($product, 'نامعتبر', false, false);
    $service = app(ProductSalesStatusBulkService::class);
    bulkExecute($service, [$product->id], 'deactivate');

    $preview = $service->preview([$product->id], 'activate', 'multiple_products');
    expect($preview['effective_changes'])->toBe(1)->and($preview['unable_to_change'])->toBe(1);
    bulkExecute($service, [$product->id], 'activate');
    expect($valid->fresh()->sales_enabled)->toBeTrue()->and($structural->fresh()->sales_enabled)->toBeFalse()->and($product->fresh()->is_sellable)->toBeTrue();
});

it('rolls back every mutation and the document when an item insert fails', function (): void {
    $category = bulkCategory('دسته');
    $product = bulkProduct($category, 'کالا');
    $variant = bulkVariant($product, 'تنوع');
    $service = app(ProductSalesStatusBulkService::class);
    $preview = $service->preview([$product->id], 'deactivate', 'multiple_products');
    DB::unprepared("CREATE TRIGGER force_bulk_failure BEFORE INSERT ON product_deactivation_document_items WHEN NEW.variant_id = {$variant->id} BEGIN SELECT RAISE(ABORT, 'forced bulk failure'); END");

    try {
        $service->execute([$product->id], 'deactivate', 'multiple_products', 'management_decision', null, User::factory()->create(), $preview['preview_token']);
    } catch (QueryException) {
        // Expected simulated mid-transaction failure.
    }

    expect($variant->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue()->and(ProductDeactivationDocument::query()->count())->toBe(0);
});

it('serializes overlapping confirmations and prevents duplicate documents', function (): void {
    $category = bulkCategory('دسته');
    $product = bulkProduct($category, 'کالا');
    bulkVariant($product, 'تنوع');
    $service = app(ProductSalesStatusBulkService::class);
    $preview = $service->preview([$product->id], 'deactivate', 'multiple_products');
    $actor = User::factory()->create();
    $service->execute([$product->id], 'deactivate', 'multiple_products', 'management_decision', null, $actor, $preview['preview_token']);

    try {
        $service->execute([$product->id], 'deactivate', 'multiple_products', 'management_decision', null, $actor, $preview['preview_token']);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('preview_token');
    }
    expect(ProductDeactivationDocument::query()->count())->toBe(1)->and(ProductDeactivationDocument::query()->first()->items_count)->toBe(1);
});

it('rejects zero effective changes and keeps audit command read only', function (): void {
    $category = bulkCategory('دسته');
    $product = bulkProduct($category, 'کالا');
    bulkVariant($product, 'خاموش', true, false);
    $service = app(ProductSalesStatusBulkService::class);
    $preview = $service->preview([$product->id], 'deactivate', 'multiple_products');
    expect($preview['effective_changes'])->toBe(0);
    $this->artisan('product-sales-status:audit')->assertSuccessful();

    $service->execute([$product->id], 'deactivate', 'multiple_products', 'management_decision', null, User::factory()->create(), $preview['preview_token']);
})->throws(ValidationException::class, 'هیچ موردی برای تغییر وضعیت وجود ندارد');
