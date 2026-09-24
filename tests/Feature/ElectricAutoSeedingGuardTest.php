<?php

use App\Http\Controllers\ProductController;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/**
 * Every app/ file that may mention the default colors, and why it is safe.
 * Adding a file here requires proving it cannot create a ProductVariant.
 */
function autoSeedAllowedColorFiles(): array
{
    return [
        'Services/DefaultProductDesignService.php' => 'read-only recognizer of historical synthetic variants',
        'Services/SyntheticDefaultVariantClassifier.php' => 'read-only classification signal',
        'Http/Controllers/ColorController.php' => 'seeds the colors palette table, not variants',
        'Http/Controllers/ProductImportController.php' => 'sample rows of the downloadable CSV template',
    ];
}

function autoSeedElectricCategory(): Category
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '91']);

    return Category::query()->create([
        'name' => 'Auto-seed child '.uniqid(),
        'code' => (string) (10 + Category::query()->count()),
        'parent_id' => $root->id,
    ]);
}

function autoSeedStore(Category $category, string $name, array $overrides = []): Product
{
    app(ProductController::class)->store(Request::create(route('products.store'), 'POST', array_merge([
        'category_id' => $category->id,
        'name' => $name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 100,
        'buy_price' => 60,
        'is_sellable' => true,
    ], $overrides)));

    return Product::query()->where('name', $name)->firstOrFail();
}

function autoSeedUpdate(Product $product, array $overrides = []): Product
{
    app(ProductController::class)->update(Request::create(route('products.update', $product), 'PUT', array_merge([
        'category_id' => $product->category_id,
        'name' => $product->name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 100,
        'buy_price' => 60,
    ], $overrides)), $product);

    return $product->fresh();
}

function autoSeedColorVariants(Product $product): int
{
    return ProductVariant::query()
        ->where('product_id', $product->id)
        ->where(fn ($query) => $query->whereIn('variety_name', ['مشکی', 'سفید'])
            ->orWhere('variant_name', 'like', '%مشکی%')
            ->orWhere('variant_name', 'like', '%سفید%'))
        ->count();
}

it('mentions black or white only in allow-listed files that cannot create variants', function (): void {
    $unexpected = [];
    $creators = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $contents = $file->getContents();
        if (! str_contains($contents, 'مشکی') && ! str_contains($contents, 'سفید')) {
            continue;
        }

        $relative = str_replace('\\', '/', $file->getRelativePathname());
        if (! array_key_exists($relative, autoSeedAllowedColorFiles())) {
            $unexpected[] = $relative;
        }
        if (preg_match('/ProductVariant::(query\(\)->)?(create|insert|firstOrCreate|updateOrCreate)\(|variants\(\)->(create|createMany)\(/', $contents)) {
            $creators[] = $relative;
        }
    }

    expect($unexpected)->toBe([])
        ->and($creators)->toBe([]);
});

it('has no writer of automatic electric default-color provenance anywhere in app/', function (): void {
    $writers = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $contents = $file->getContents();
        if (preg_match("/'action'\s*=>\s*'electric_default_color_created'/", $contents)
            || str_contains($contents, 'function ensureElectricDefaultColors')) {
            $writers[] = $file->getRelativePathname();
        }
    }

    expect($writers)->toBe([]);
});

it('creates exactly the variants the request asks for on an electrical product', function (): void {
    $simple = autoSeedStore(autoSeedElectricCategory(), 'Auto-seed simple');
    $designed = autoSeedStore(autoSeedElectricCategory(), 'Auto-seed designed', [
        'use_designs' => true,
        'design_count' => 2,
        'design_notes' => ['قرمز', 'آبی'],
    ]);

    expect($simple->variants()->count())->toBe(1)
        ->and(autoSeedColorVariants($simple))->toBe(0)
        ->and($designed->variants()->count())->toBe(2)
        ->and($designed->variants()->pluck('variety_name')->sort()->values()->all())->toBe(['آبی', 'قرمز'])
        ->and(autoSeedColorVariants($designed))->toBe(0);
});

it('adds exactly the requested variants when an electrical product is updated', function (): void {
    $product = autoSeedStore(autoSeedElectricCategory(), 'Auto-seed edited');
    $idsBefore = $product->variants()->pluck('id')->all();

    $product = autoSeedUpdate($product);
    expect($product->variants()->pluck('id')->all())->toBe($idsBefore);

    $product = autoSeedUpdate($product, ['use_designs' => true, 'design_count' => 2, 'design_notes' => ['قرمز', 'آبی']]);
    $added = $product->variants()->whereNotIn('id', $idsBefore)->get();

    expect($added)->toHaveCount(2)
        ->and($added->pluck('variety_name')->sort()->values()->all())->toBe(['آبی', 'قرمز'])
        ->and(autoSeedColorVariants($product))->toBe(0);
});

it('never turns use_designs on by itself for an electrical product', function (): void {
    $product = autoSeedStore(autoSeedElectricCategory(), 'Auto-seed flag');
    expect((bool) ($product->models['use_designs'] ?? false))->toBeFalse();

    $product = autoSeedUpdate($product, ['category_id' => autoSeedElectricCategory()->id]);
    expect((bool) ($product->models['use_designs'] ?? false))->toBeFalse()
        ->and((int) ($product->models['design_count'] ?? 0))->toBe(0);
});

it('never logs automatic electric default-color creation on create or update', function (): void {
    $product = autoSeedStore(autoSeedElectricCategory(), 'Auto-seed log');
    autoSeedUpdate($product);
    autoSeedUpdate($product->fresh(), ['use_designs' => true, 'design_count' => 1, 'design_notes' => ['مشکی']]);

    expect(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});
