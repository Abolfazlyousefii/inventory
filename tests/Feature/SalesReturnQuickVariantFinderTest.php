<?php

use App\Http\Controllers\SalesReturnLookupController;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function makeQuickReturnVariant(Category $category, ?ModelList $model, string $code, int $stock = 0): ProductVariant
{
    $product = Product::query()->firstOrCreate(
        ['short_barcode' => $code],
        [
            'category_id' => $category->id,
            'name' => 'گارد سیلیکون اورجینال سامسونگ',
            'code' => '03'.$code,
            'sku' => 'SKU-'.$code,
            'barcode' => 'BAR-'.$code,
            'stock' => $stock,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]
    );

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => $model?->id,
        'variant_name' => 'بادمجونی '.$code,
        'variety_name' => 'بادمجونی '.$code,
        'variant_code' => 'CASE-'.$code,
        'variety_code' => $code,
        'stock' => $stock,
        'reserved' => 0,
        'buy_price' => 500,
        'sell_price' => 1000,
        'is_active' => false,
        'sales_enabled' => false,
    ]);
}

function quickReturnSearch(string $q, int $page = 1): array
{
    return app(SalesReturnLookupController::class)->products(
        Request::create('/vouchers/section/return-from-sale/products/search', 'GET', [
            'q' => $q,
            'page' => $page,
        ])
    );
}

it('finds products from descendant categories without requiring stock or a selling price', function () {
    $root = Category::create(['name' => 'برقیجات']);
    $child = Category::create(['name' => 'کابل', 'parent_id' => $root->id]);
    $variant = makeQuickReturnVariant($child, null, 'CAT1');
    $variant->product->update(['price' => 0]);
    $response = app(SalesReturnLookupController::class)->categoryProducts($root, Request::create('/'));
    expect(collect($response['data'])->pluck('id'))->toContain($variant->product_id);
    $categories = app(SalesReturnLookupController::class)->categories(Request::create('/'));
    expect(collect($categories['data'])->firstWhere('id', $root->id)['children_count'])->toBe(1);
    expect(quickReturnSearch('CASE-CAT1')['results'][0]['catalog_sell_price'])->toBe(0);
});

it('finds S25 FE variants through the model list even if out of stock and not sellable', function () {
    $category = Category::query()->create(['name' => 'گارد']);
    $model = ModelList::query()->create([
        'model_name' => 'Galaxy S25 FE',
        'brand' => 'Samsung',
        'code' => 'SM-S25FE',
    ]);
    $variant = makeQuickReturnVariant($category, $model, '3050');

    foreach (['S25 FE', 's25fe', 's25-fe', 's25_fe'] as $term) {
        $response = quickReturnSearch($term);
        expect(collect($response['results'])->pluck('variant_id'))->toContain($variant->id);
        expect($response['results'][0]['stock'])->toBe(0);
        expect($response['results'][0]['model_list'])->toContain('S25 FE');
        expect($response['results'][0]['is_active'])->toBeFalse();
    }
});

it('finds product codes and pages through more than 40 matching variants', function () {
    $category = Category::query()->create(['name' => 'گارد']);
    $model = ModelList::query()->create(['model_name' => 'Galaxy S25 FE', 'code' => 'SM-S25FE']);

    for ($i = 1; $i <= 41; $i++) {
        makeQuickReturnVariant($category, $model, str_pad((string) (3500 + $i), 4, '0', STR_PAD_LEFT));
    }

    $first = quickReturnSearch('S25 FE', 1);
    $second = quickReturnSearch('S25 FE', 2);

    expect($first['results'])->toHaveCount(40);
    expect($first['meta']['has_more'])->toBeTrue();
    expect($second['results'])->toHaveCount(1);
    expect($second['meta']['has_more'])->toBeFalse();
    expect(collect($first['results'])->pluck('variant_id')->intersect(collect($second['results'])->pluck('variant_id')))->toBeEmpty();

    $codeResult = quickReturnSearch('CASE-3501');
    expect($codeResult['results'])->toHaveCount(1);
});

it('adds a searched variant directly to Sazeh return rows without changing invoice picker logic', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)
        ->toContain('data-add-return-variant')
        ->toContain('function addQuickReturnVariant(variant)')
        ->toContain("item_source: 'existing_product'")
        ->toContain('return_quantity: 1')
        ->toContain('bootstrap.Modal.getInstance')
        ->toContain('data-return-next-page')
        ->toContain("loadInvoiceItems()");
});
