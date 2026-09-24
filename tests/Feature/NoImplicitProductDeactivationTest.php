<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusService;
use App\Services\ProductVariantStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/** A product with three design variants plus a Base. */
function deactivationFixture(): array
{
    $category = Category::query()->create(['name' => 'Deactivation '.uniqid(), 'code' => '44']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Deactivation product',
        'sku' => uniqid('DEACT-'),
        'code' => '440001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => true, 'design_count' => 3, 'design_notes' => ['A', 'B', 'C']],
    ]);

    $variants = collect(['0000', '0001', '0002', '0003'])->map(fn (string $varietyCode) => ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' '.$varietyCode,
        'variety_name' => $varietyCode === '0000' ? '—' : 'Design '.$varietyCode,
        'variety_code' => $varietyCode,
        'variant_code' => '440001000'.substr($varietyCode, -2),
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]));

    return [$product, $variants];
}

/** @return array<int,bool> */
function activeStates(Product $product): array
{
    return ProductVariant::query()
        ->where('product_id', $product->id)
        ->orderBy('id')
        ->pluck('is_active', 'id')
        ->map(fn ($active): bool => (bool) $active)
        ->all();
}

it('has no implicit caller of deactivateInvalidVariants anywhere in the application', function (): void {
    $callers = [];
    foreach ((new Finder)->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
        if (str_contains($file->getContents(), 'deactivateInvalidVariants(')
            && $file->getRealPath() !== realpath(app_path('Services/ProductVariantStructureService.php'))) {
            $callers[] = $file->getRelativePathname();
        }
    }

    expect($callers)->toBe([]);
});

it('never deactivates variants when a product is saved with a structure that invalidates them', function (): void {
    [$product] = deactivationFixture();
    $before = activeStates($product);

    // Designs switched off: 0001-0003 fall outside the new structure.
    app(ProductController::class)->update(Request::create(route('products.update', $product), 'PUT', [
        'category_id' => $product->category_id,
        'name' => $product->name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 100,
        'buy_price' => 50,
    ]), $product);

    expect(app(ProductVariantStructureService::class)->invalidVariants($product->fresh())->count())->toBeGreaterThan(0)
        ->and(activeStates($product))->toBe($before);
});

it('writes nothing and only reports when not confirmed', function (): void {
    [$product, $variants] = deactivationFixture();
    $product->forceFill(['models' => array_merge($product->models, ['design_count' => 1])])->save();
    $before = activeStates($product);
    Log::spy();

    $report = app(ProductVariantStructureService::class)->deactivateInvalidVariants($product->fresh(), 'structure review');

    expect(activeStates($product))->toBe($before)
        ->and($report['confirmed'])->toBeFalse()
        ->and($report['deactivated'])->toBe(0)
        ->and($report['reason'])->toBe('structure review')
        ->and($report['variant_ids'])->toBe([(int) $variants[0]->id, (int) $variants[2]->id, (int) $variants[3]->id])
        ->and($report['count'])->toBe(3);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'not confirmed')
            && ($context['product_id'] ?? null) === (int) $product->id
            && ($context['count'] ?? null) === 3)
        ->once();
});

it('requires an explicit reason', function (): void {
    [$product] = deactivationFixture();

    expect(fn () => app(ProductVariantStructureService::class)->deactivateInvalidVariants($product, '  '))
        ->toThrow(InvalidArgumentException::class);
});

it('still deactivates when an explicit caller confirms', function (): void {
    [$product, $variants] = deactivationFixture();
    $product->forceFill(['models' => array_merge($product->models, ['design_count' => 1])])->save();

    $report = app(ProductVariantStructureService::class)->deactivateInvalidVariants($product->fresh(), 'explicit structure cleanup', true);

    expect($report['deactivated'])->toBe(3)
        ->and((bool) $variants[1]->fresh()->is_active)->toBeTrue()
        ->and((bool) $variants[0]->fresh()->is_active)->toBeFalse();
});

it('keeps the explicit user deactivation document path working', function (): void {
    [$product, $variants] = deactivationFixture();

    app(ProductSalesStatusService::class)->change(
        $product->id, 'deactivate', 'variants', [$variants[1]->id], 'management_decision', null, User::factory()->create(),
    );

    expect((bool) $variants[1]->fresh()->sales_enabled)->toBeFalse()
        ->and((bool) $variants[2]->fresh()->sales_enabled)->toBeTrue()
        // The explicit document path controls sales status; it never flips is_active.
        ->and(activeStates($product))->toBe(array_fill_keys($variants->pluck('id')->all(), true));
});
