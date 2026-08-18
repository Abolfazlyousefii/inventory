<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function statusProduct(array $productAttributes = []): Product
{
    $category = Category::query()->create(['name' => 'دسته '.uniqid(), 'code' => uniqid()]);

    return Product::query()->create(array_merge(['category_id' => $category->id, 'name' => 'کالا', 'sku' => uniqid('P-'), 'stock' => 0, 'price' => 1000, 'is_sellable' => true], $productAttributes));
}

function statusVariant(Product $product, string $name, bool $active = true, bool $salesEnabled = true): ProductVariant
{
    return ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => $name, 'variant_code' => uniqid('V-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => $active, 'sales_enabled' => $salesEnabled]);
}

function changeStatus(Product $product, string $action, string $scope, array $variants = []): ProductDeactivationDocument
{
    $user = User::factory()->create();

    return app(ProductSalesStatusService::class)->change($product->id, $action, $scope, collect($variants)->map->id->all(), $action === 'activate' ? 'restocked' : 'management_decision', null, $user);
}

it('does not let the product edit route overwrite sales status', function (): void {
    $product = statusProduct(['is_sellable' => true]);

    $request = Request::create(route('products.update', $product), 'PUT', [
        'category_id' => $product->category_id,
        'name' => 'نام ویرایش‌شده',
        'is_sellable' => 0,
    ]);
    app(ProductController::class)->update($request, $product);

    expect($product->fresh()->is_sellable)->toBeTrue();
});

it('deactivates a whole product without changing structural status or inventory', function (): void {
    $product = statusProduct();
    $first = statusVariant($product, 'اول');
    $second = statusVariant($product, 'دوم');
    $before = [$first->stock, $second->stock];

    $document = changeStatus($product, 'deactivate', 'product');

    expect($product->fresh()->is_sellable)->toBeFalse()
        ->and($first->fresh()->sales_enabled)->toBeFalse()->and($first->fresh()->is_active)->toBeTrue()
        ->and($second->fresh()->sales_enabled)->toBeFalse()->and($second->fresh()->is_active)->toBeTrue()
        ->and([$first->fresh()->stock, $second->fresh()->stock])->toBe($before)
        ->and($document->items)->toHaveCount(2)
        ->and($document->created_by)->not->toBeNull();
});

it('deactivates only selected variants and recalculates product aggregate', function (): void {
    $product = statusProduct();
    $first = statusVariant($product, 'اول');
    $second = statusVariant($product, 'دوم');

    $document = changeStatus($product, 'deactivate', 'variants', [$first]);
    expect($first->fresh()->sales_enabled)->toBeFalse()->and($second->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue()
        ->and($document->items->first()->variant_name_snapshot)->toBe('اول');

    changeStatus($product, 'deactivate', 'variants', [$second]);
    expect($product->fresh()->is_sellable)->toBeFalse();
});

it('reactivates an eligible variant and rejects a structurally inactive variant', function (): void {
    $product = statusProduct(['is_sellable' => false]);
    $eligible = statusVariant($product, 'مجاز', true, false);
    changeStatus($product, 'activate', 'variants', [$eligible]);
    expect($eligible->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue();

    $blocked = statusVariant($product, 'ساختاری', false, false);
    changeStatus($product, 'activate', 'variants', [$blocked]);
})->throws(ValidationException::class, 'این تنوع از نظر ساختاری غیرفعال است');

it('whole product reactivation preserves independently disabled variants', function (): void {
    $product = statusProduct();
    $independent = statusVariant($product, 'مستقل');
    $productScoped = statusVariant($product, 'سطح کالا');
    changeStatus($product, 'deactivate', 'variants', [$independent]);
    changeStatus($product, 'deactivate', 'product');

    changeStatus($product, 'activate', 'product');

    expect($independent->fresh()->sales_enabled)->toBeFalse()->and($productScoped->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue();
});

it('keeps independent history and refuses duplicate events', function (): void {
    $product = statusProduct();
    $variant = statusVariant($product, 'تنوع');
    changeStatus($product, 'deactivate', 'variants', [$variant]);
    changeStatus($product, 'activate', 'variants', [$variant]);
    changeStatus($product, 'deactivate', 'variants', [$variant]);
    expect(ProductDeactivationDocument::query()->where('product_id', $product->id)->count())->toBe(3);

    try {
        changeStatus($product, 'deactivate', 'variants', [$variant]);
    } catch (ValidationException) {
        // Expected controlled failure.
    }
    expect(ProductDeactivationDocument::query()->where('product_id', $product->id)->count())->toBe(3);
});

it('audits inconsistencies without mutation by default', function (): void {
    $product = statusProduct(['is_sellable' => true]);
    $variant = statusVariant($product, 'ناسازگار', false, true);

    $this->artisan('product-sales-status:audit')->assertSuccessful();
    expect($variant->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue();
});
