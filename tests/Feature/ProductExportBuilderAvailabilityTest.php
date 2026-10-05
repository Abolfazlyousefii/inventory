<?php

use App\Models\{Category, ModelList, Product, ProductVariant, Warehouse, WarehouseStock};
use App\Services\ProductExportBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function exportAvailabilityFixture(): array
{
    Http::fake();
    $category = Category::withoutEvents(fn () => Category::create(['name' => 'Export availability']));
    $product = Product::withoutEvents(fn () => Product::create(['name' => 'Case export', 'sku' => 'EXPORT-AVAILABLE', 'code' => '999991', 'category_id' => $category->id, 'stock' => 99, 'price' => 100]));
    $central = Warehouse::create(['name' => 'Central', 'type' => 'central']);
    $return = Warehouse::create(['name' => 'Return', 'type' => 'return']);
    $a = ModelList::create(['brand' => 'Audit', 'model_name' => 'Model A']);
    $b = ModelList::create(['brand' => 'Audit', 'model_name' => 'Model B']);
    $variants = [];
    foreach ([[$a, 'Black', 5, 100], [$a, 'Red', 0, 300], [$b, 'Blue', 0, 400]] as $index => [$model, $color, $quantity, $price]) {
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $product->id, 'model_list_id' => $model->id, 'variant_name' => $model->model_name, 'variety_name' => $color, 'variety_code' => '000'.($index + 1), 'variant_code' => '9999910000'.$index, 'stock' => 99, 'reserved' => 7, 'sell_price' => $price, 'is_active' => true]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity]));
        $variants[] = $variant;
    }
    WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $return->id, 'product_id' => $product->id, 'product_variant_id' => $variants[2]->id, 'quantity' => 12]));
    return [$product, $variants, $central];
}

it('defaults to available models using central stock without subtracting reserved again', function () {
    [$product] = exportAvailabilityFixture();
    $card = collect(app(ProductExportBuilderService::class)->page([])['items'])->firstWhere('id', $product->id);
    expect(array_column($card['models'], 'name'))->toBe(['Model A'])
        ->and($card['stock'])->toBe(5)->and($card['models'][0]['price_max'])->toBe(100)
        ->and((int) $product->fresh()->stock)->toBe(99);
});

it('keeps all active models when availability filter is explicitly disabled', function () {
    [$product, $variants] = exportAvailabilityFixture();
    $service = app(ProductExportBuilderService::class);
    $card = collect($service->page(['in_stock' => false])['items'])->firstWhere('id', $product->id);
    $sheet = $service->selected([$product->id => [$variants[0]->id, $variants[2]->id]], false)->first();
    expect(array_column($card['models'], 'name'))->toContain('Model B')
        ->and($sheet['selected_models'])->toContain('Model B')->and($sheet['approximate_price'])->toBe(400);
});

it('filters empty models and colors again in final output and computes price from available colors', function () {
    [$product, $variants] = exportAvailabilityFixture();
    $sheet = app(ProductExportBuilderService::class)->selected([$product->id => [$variants[0]->id, $variants[2]->id]])->first();
    expect($sheet['selected_models'])->toBe(['Model A'])
        ->and(array_column($sheet['selected_colors'], 'name'))->toBe(['Black'])
        ->and($sheet['approximate_price'])->toBe(100)->and($sheet['total_stock'])->toBe(5);
});

it('keeps model selection when its representative color becomes empty but another color is available', function () {
    [$product, $variants] = exportAvailabilityFixture();
    DB::table('warehouse_stocks')->where('product_variant_id', $variants[0]->id)->where('quantity', 5)->update(['quantity' => 0]);
    DB::table('warehouse_stocks')->where('product_variant_id', $variants[1]->id)->update(['quantity' => 2]);
    $sheet = app(ProductExportBuilderService::class)->selected([$product->id => [$variants[0]->id]])->first();
    expect($sheet['selected_models'])->toBe(['Model A'])
        ->and(array_column($sheet['selected_colors'], 'name'))->toBe(['Red'])->and($sheet['approximate_price'])->toBe(300);
});

it('rejects a selected model that has become completely empty rather than inventing a general model', function () {
    [$product, $variants] = exportAvailabilityFixture();
    DB::table('warehouse_stocks')->where('product_variant_id', $variants[0]->id)->update(['quantity' => 0]);
    app(ProductExportBuilderService::class)->selected([$product->id => [$variants[0]->id]]);
})->throws(ValidationException::class);

it('excludes empty products even if their cached summary says available', function () {
    [$product, $variants, $central] = exportAvailabilityFixture();
    DB::table('warehouse_stocks')->where('warehouse_id', $central->id)->update(['quantity' => 0]);
    expect(app(ProductExportBuilderService::class)->page([])['total'])->toBe(0);
});

it('reads stock again when printing and returns a clear validation error for stale selections', function () {
    $this->withoutMiddleware();
    [$product, $variants, $central] = exportAvailabilityFixture();
    DB::table('warehouse_stocks')->where('warehouse_id', $central->id)->update(['quantity' => 0]);
    $payload = ['selection' => [$product->id => [$variants[0]->id]]];
    $this->postJson(route('admin.product-exports.builder.print'), $payload)->assertUnprocessable()->assertJsonValidationErrors('selection');
    $this->postJson(route('admin.product-exports.builder.preview'), $payload + ['copy_text' => true])->assertUnprocessable()->assertJsonValidationErrors('selection');
});

it('supports a simple product without variants using only its central warehouse stock', function () {
    [$product, $variants, $central] = exportAvailabilityFixture();
    $simple = Product::withoutEvents(fn () => Product::create(['name' => 'Simple', 'sku' => 'EXPORT-SIMPLE', 'code' => '999992', 'category_id' => $product->category_id, 'stock' => 99, 'price' => 150]));
    WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central->id, 'product_id' => $simple->id, 'quantity' => 3]));
    $sheet = app(ProductExportBuilderService::class)->selected([$simple->id => [0]])->first();
    expect($sheet['selected_models'])->toBe(['مدل عمومی'])->and($sheet['total_stock'])->toBe(3);
    DB::table('warehouse_stocks')->where('product_id', $simple->id)->update(['quantity' => 0]);
    expect(collect(app(ProductExportBuilderService::class)->page([])['items'])->pluck('id')->all())->not->toContain($simple->id);
});

it('uses the same availability rule for preview print and copied text', function () {
    $this->withoutMiddleware();
    [$product, $variants] = exportAvailabilityFixture();
    $payload = ['selection' => [$product->id => [$variants[0]->id, $variants[2]->id]], 'show_price' => true];
    $this->post(route('admin.product-exports.builder.preview'), $payload)->assertOk()->assertSee('Model A')->assertDontSee('Model B')->assertDontSee('Red');
    $this->post(route('admin.product-exports.builder.print'), $payload)->assertOk()->assertSee('Model A')->assertDontSee('Model B');
    $this->postJson(route('admin.product-exports.builder.preview'), $payload + ['copy_text' => true])->assertOk()->assertJsonPath('text', "Case export\n• Model A\nقیمت حدودی: ۱۰۰ ریال");
    $this->postJson(route('admin.product-exports.builder.preview'), $payload + ['copy_text' => true, 'in_stock' => false])->assertOk()->assertSee('Model B');
});
