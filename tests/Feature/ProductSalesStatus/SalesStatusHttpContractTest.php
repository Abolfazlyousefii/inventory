<?php

use App\Http\Controllers\ProductDeactivationDocumentController;
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

function httpStatusProduct(array $attributes = []): Product
{
    $category = Category::query()->create(['name' => 'HTTP '.uniqid(), 'code' => uniqid('HC-')]);

    return Product::query()->create(array_merge([
        'category_id' => $category->id,
        'name' => 'کالای HTTP',
        'sku' => uniqid('HP-'),
        'stock' => 0,
        'price' => 1000,
        'is_sellable' => true,
    ], $attributes));
}

function httpStatusVariant(Product $product, string $name = 'تنوع', bool $active = true, bool $enabled = true): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $name,
        'variant_code' => uniqid('HV-'),
        'sell_price' => 1000,
        'stock' => 4,
        'reserved' => 1,
        'is_active' => $active,
        'sales_enabled' => $enabled,
    ]);
}

function statusControllerRequest(array $payload, User $user): Request
{
    $request = Request::create('/product-deactivation-documents', 'POST', $payload);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('keeps every sales status route authenticated, named, and on its declared HTTP method', function (): void {
    $expected = [
        'product-deactivation-documents.index' => 'GET',
        'product-deactivation-documents.create' => 'GET',
        'product-deactivation-documents.products.search' => 'GET',
        'product-deactivation-documents.products.variants' => 'GET',
        'product-deactivation-documents.bulk.create' => 'GET',
        'product-deactivation-documents.bulk.categories.children' => 'GET',
        'product-deactivation-documents.bulk.preview' => 'POST',
        'product-deactivation-documents.bulk.store' => 'POST',
        'product-deactivation-documents.store' => 'POST',
        'product-deactivation-documents.show' => 'GET',
    ];

    foreach ($expected as $name => $method) {
        $route = app('router')->getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route->methods())->toContain($method)
            ->and($route->gatherMiddleware())->toContain('auth', 'route.permission');
    }

    $this->get(route('product-deactivation-documents.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('product-deactivation-documents.index'))->assertForbidden();
});

it('rejects tampered actions, scopes, cross-product variants, and missing custom reasons without mutation', function (): void {
    $user = User::factory()->create();
    $first = httpStatusProduct();
    $foreign = httpStatusProduct();
    $variant = httpStatusVariant($first);
    $controller = app(ProductDeactivationDocumentController::class);
    $service = app(ProductSalesStatusService::class);

    foreach ([
        ['product_id' => $first->id, 'action_type' => 'pause', 'scope_type' => 'product', 'reason_type' => 'management_decision'],
        ['product_id' => $first->id, 'action_type' => 'deactivate', 'scope_type' => 'all_database', 'reason_type' => 'management_decision'],
        ['product_id' => $first->id, 'action_type' => 'deactivate', 'scope_type' => 'product', 'reason_type' => 'custom'],
    ] as $payload) {
        try {
            $controller->store(statusControllerRequest($payload, $user), $service);
            $this->fail('The tampered request was accepted.');
        } catch (ValidationException) {
            expect(ProductDeactivationDocument::query()->count())->toBe(0);
        }
    }

    expect(fn () => $service->change($foreign->id, 'deactivate', 'variants', [$variant->id], 'management_decision', null, $user))
        ->toThrow(ValidationException::class, 'یک یا چند تنوع به این کالا تعلق ندارد');
    expect($variant->fresh()->sales_enabled)->toBeTrue()
        ->and(ProductDeactivationDocument::query()->count())->toBe(0);
});

it('accepts every documented reason and requires text only for custom reasons', function (string $action, string $reason): void {
    $product = httpStatusProduct(['is_sellable' => $action === 'deactivate']);
    $variant = httpStatusVariant($product, enabled: $action === 'deactivate');
    $user = User::factory()->create();
    $payload = [
        'product_id' => $product->id,
        'action_type' => $action,
        'scope_type' => 'variants',
        'variant_ids' => [$variant->id],
        'reason_type' => $reason,
        'reason_text' => $reason === 'custom' ? 'توضیح معتبر سفارشی' : null,
    ];

    app(ProductDeactivationDocumentController::class)->store(
        statusControllerRequest($payload, $user),
        app(ProductSalesStatusService::class),
    );

    $document = ProductDeactivationDocument::query()->sole();
    expect($document->action_type)->toBe($action)
        ->and($document->reason_type)->toBe($reason)
        ->and($document->items_count)->toBe(1);
})->with([
    ['deactivate', 'supplier_ended'],
    ['deactivate', 'sales_stopped'],
    ['deactivate', 'quality_issue'],
    ['deactivate', 'long_term_out_of_stock'],
    ['deactivate', 'management_decision'],
    ['deactivate', 'wrong_registration'],
    ['deactivate', 'custom'],
    ['activate', 'restocked'],
    ['activate', 'supplier_resumed'],
    ['activate', 'management_reactivation'],
    ['activate', 'issue_resolved'],
    ['activate', 'custom'],
]);

it('searches names codes short barcodes and variant codes with bounded pagination metadata', function (): void {
    $nameMatch = httpStatusProduct(['name' => 'هدف آلفا']);
    $codeMatch = httpStatusProduct(['code' => 'CODE-NEEDLE']);
    $barcodeMatch = httpStatusProduct(['short_barcode' => '7391']);
    $variantMatch = httpStatusProduct();
    httpStatusVariant($variantMatch, 'تنوع جست‌وجو')->update(['variant_code' => 'VAR-NEEDLE']);
    $controller = app(ProductDeactivationDocumentController::class);

    foreach ([
        ['آلفا', $nameMatch->id],
        ['CODE-NEEDLE', $codeMatch->id],
        ['7391', $barcodeMatch->id],
        ['VAR-NEEDLE', $variantMatch->id],
    ] as [$term, $expectedId]) {
        $payload = $controller->searchProducts(Request::create('/search', 'GET', ['q' => $term]))->getData(true);
        expect(collect($payload['data'])->pluck('id'))->toContain($expectedId)
            ->and(count($payload['data']))->toBeLessThanOrEqual(15)
            ->and($payload)->toHaveKey('next_page_url');
    }
});

it('lazy loads exact structural and commercial variant fields and aggregate counts', function (): void {
    $product = httpStatusProduct();
    $enabled = httpStatusVariant($product, 'فعال', true, true);
    $disabled = httpStatusVariant($product, 'خاموش فروش', true, false);
    httpStatusVariant($product, 'ساختاری خاموش', false, false);

    $payload = app(ProductDeactivationDocumentController::class)->variants($product)->getData(true);

    expect($payload['product']['structural_variants_count'])->toBe(2)
        ->and($payload['product']['sellable_variants_count'])->toBe(1)
        ->and(collect($payload['variants'])->firstWhere('id', $enabled->id))->toMatchArray([
            'variant_name' => 'فعال', 'is_active' => true, 'sales_enabled' => true,
        ])
        ->and(collect($payload['variants'])->firstWhere('id', $disabled->id))->toMatchArray([
            'variant_name' => 'خاموش فروش', 'is_active' => true, 'sales_enabled' => false,
        ]);
});

it('preserves product and variant name snapshots after live records are renamed', function (): void {
    $product = httpStatusProduct(['name' => 'نام تاریخی کالا']);
    $variant = httpStatusVariant($product, 'نام تاریخی تنوع');
    $document = app(ProductSalesStatusService::class)->change(
        $product->id, 'deactivate', 'variants', [$variant->id], 'management_decision', null, User::factory()->create(),
    );

    $product->update(['name' => 'نام جدید کالا']);
    $variant->update(['variant_name' => 'نام جدید تنوع']);

    expect($document->fresh()->product_name_snapshot)->toBe('نام تاریخی کالا')
        ->and($document->items()->sole()->product_name_snapshot)->toBe('نام تاریخی کالا')
        ->and($document->items()->sole()->variant_name_snapshot)->toBe('نام تاریخی تنوع');
});
