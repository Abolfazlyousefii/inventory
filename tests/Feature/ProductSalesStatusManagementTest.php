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

it('whole product reactivation restores legacy commercial stops', function (): void {
    $product = statusProduct();
    $independent = statusVariant($product, 'مستقل');
    $productScoped = statusVariant($product, 'سطح کالا');
    changeStatus($product, 'deactivate', 'variants', [$independent]);
    changeStatus($product, 'deactivate', 'product');

    changeStatus($product, 'activate', 'product');

    expect($independent->fresh()->sales_enabled)->toBeTrue()->and($productScoped->fresh()->sales_enabled)->toBeTrue()->and($product->fresh()->is_sellable)->toBeTrue();
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

it('activates commercial stops without history and keeps structural inactivity and quantities', function (): void {
    $product = statusProduct(['is_sellable' => false]);
    $valid = statusVariant($product, 'valid', true, false);
    $invalid = statusVariant($product, 'legacy', false, false);
    $valid->update(['stock' => 8, 'reserved' => 2]);
    changeStatus($product, 'activate', 'product');
    expect($valid->fresh()->sales_enabled)->toBeTrue()
        ->and($invalid->fresh()->is_active)->toBeFalse()
        ->and($invalid->fresh()->sales_enabled)->toBeFalse()
        ->and($valid->fresh()->stock)->toBe(8)
        ->and($valid->fresh()->reserved)->toBe(2);
});

it('keeps stock reservations prices and structural states through repeated whole product cycles', function (): void {
    $product = statusProduct(['stock' => 18, 'reserved' => 4]);
    $first = statusVariant($product, 'first');
    $second = statusVariant($product, 'second', true, false);
    $legacy = statusVariant($product, 'legacy', false, false);
    $first->update(['stock' => 12, 'reserved' => 3]);
    $second->update(['stock' => 6, 'reserved' => 1]);
    $before = $product->variants()->orderBy('id')->get(['id', 'is_active', 'stock', 'reserved', 'buy_price', 'sell_price'])->toArray();
    foreach (range(1, 3) as $cycle) {
        changeStatus($product, 'deactivate', 'product');
        expect($product->fresh()->is_sellable)->toBeFalse();
        changeStatus($product, 'activate', 'product');
        expect($product->fresh()->is_sellable)->toBeTrue()
            ->and($first->fresh()->sales_enabled)->toBeTrue()
            ->and($second->fresh()->sales_enabled)->toBeTrue()
            ->and($legacy->fresh()->sales_enabled)->toBeFalse()
            ->and($product->variants()->orderBy('id')->get(['id', 'is_active', 'stock', 'reserved', 'buy_price', 'sell_price'])->toArray())->toBe($before)
            ->and([$product->fresh()->stock, $product->fresh()->reserved])->toBe([18, 4]);
    }
    expect(ProductDeactivationDocument::query()->count())->toBe(6);
});

it('rolls back single product changes and history on a mid-document failure', function (): void {
    $product = statusProduct();
    $first = statusVariant($product, 'first');
    $second = statusVariant($product, 'second');
    Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER force_single_status_failure BEFORE INSERT ON product_deactivation_document_items WHEN NEW.variant_id = {$second->id} BEGIN SELECT RAISE(ABORT, 'forced single failure'); END");
    expect(fn () => changeStatus($product, 'deactivate', 'product'))->toThrow(Illuminate\Database\QueryException::class);
    expect($first->fresh()->sales_enabled)->toBeTrue()
        ->and($second->fresh()->sales_enabled)->toBeTrue()
        ->and($product->fresh()->is_sellable)->toBeTrue()
        ->and(ProductDeactivationDocument::query()->count())->toBe(0);
});
