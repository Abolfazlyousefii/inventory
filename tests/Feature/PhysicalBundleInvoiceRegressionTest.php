<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\WarehouseStockService;
use App\Support\FinanceDocumentVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

beforeEach(function (): void {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->withoutVite();

    // The recovered baseline migrations can precede the bundle feature.
    if (!Schema::hasColumn('products', 'is_bundle')) {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('is_bundle')->default(false));
    }
    if (!Schema::hasColumn('products', 'bundle_stock_mode')) {
        Schema::table('products', fn (Blueprint $table) => $table->string('bundle_stock_mode')->default('physical'));
    }
    if (!Schema::hasTable('product_bundle_components')) {
        Schema::create('product_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->unsignedInteger('quantity');
            $table->string('discount_type')->default('percent');
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('bundle_component_allocations')) {
        Schema::create('bundle_component_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->unsignedInteger('remaining');
            $table->timestamps();
        });
    }

    $this->seller = User::factory()->create();
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($this->seller);
    $this->centralWarehouse = WarehouseStockService::centralWarehouseId();

    $category = Category::withoutEvents(fn () => Category::create([
        'name' => 'Physical bundle invoice regression',
    ]));
    $this->bundle = Product::withoutEvents(fn () => Product::create([
        'category_id' => $category->id,
        'name' => 'Regression physical bundle',
        'sku' => 'QA-PHYSICAL-BUNDLE-'.Str::random(6),
        'code' => 'QA-PB-'.Str::random(6),
        'is_bundle' => true,
        'bundle_stock_mode' => 'physical',
        'is_sellable' => true,
        'stock' => 0,
        'reserved' => 0,
        'price' => 1000,
    ]));
    $this->bundleVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
        'product_id' => $this->bundle->id,
        'variant_name' => 'Finished package',
        'variant_code' => 'QA-BUNDLE-'.Str::random(6),
        'sell_price' => 1000,
        'is_active' => true,
        'sales_enabled' => true,
        'stock' => 0,
        'reserved' => 0,
    ]));
    $component = Product::withoutEvents(fn () => Product::create([
        'category_id' => $category->id,
        'name' => 'Bundle component',
        'sku' => 'QA-COMPONENT-'.Str::random(6),
        'is_bundle' => false,
        'is_sellable' => true,
        'stock' => 0,
        'price' => 500,
    ]));
    $this->componentVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
        'product_id' => $component->id,
        'variant_name' => 'Component',
        'variant_code' => 'QA-COMP-'.Str::random(6),
        'sell_price' => 500,
        'is_active' => true,
        'sales_enabled' => true,
        'stock' => 0,
    ]));
    DB::table('product_bundle_components')->insert([
        'bundle_variant_id' => $this->bundleVariant->id,
        'component_variant_id' => $this->componentVariant->id,
        'quantity' => 2,
    ]);

    // A ready-made physical package is a single warehouse SKU. No component
    // stock movement should occur when the package is sold.
    WarehouseStockService::set($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id, 1);
    WarehouseStockService::set($this->centralWarehouse, $component->id, $this->componentVariant->id, 3);
    $this->reservationToken = (string) Str::uuid();
    $this->bundleLine = [
        'product_id' => $this->bundle->id,
        'variant_id' => $this->bundleVariant->id,
        'quantity' => 1,
        'source_warehouse_id' => null,
    ];
});

it('shows a physical bundle with one finished unit in the invoice product finder', function (): void {
    $response = $this->getJson(route('preinvoice.api.product-finder', [
        'q' => 'Regression physical bundle',
        'in_stock_only' => 1,
    ]))->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($this->bundle->id);
    $product = collect($response->json('data'))->firstWhere('id', $this->bundle->id);
    expect($product['sellable_variants_count'])->toBe(1)
        ->and($product['total_available_stock'])->toBe(1);

    $this->getJson('/preinvoice/api/products/'.$this->bundle->id)
        ->assertOk()
        ->assertJsonPath('data.product.varieties.0.free_stock', 1);
});

it('reserves a physical bundle once and releases the finished unit without moving components', function (): void {
    $service = app(PreinvoiceDraftReservationService::class);
    $service->syncReservationRows($this->reservationToken, $this->seller->id, [$this->bundleLine]);
    expect(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(0)
        ->and(WarehouseStockService::available($this->centralWarehouse, $this->componentVariant->product_id, $this->componentVariant->id))->toBe(3);

    // Repeated autosave/sync must not deduct the same package twice.
    $service->syncReservationRows($this->reservationToken, $this->seller->id, [$this->bundleLine]);
    expect(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(0)
        ->and(PreinvoiceDraftReservation::query()->where('token', $this->reservationToken)
            ->whereNull('released_at')->count())->toBe(1);

    $service->releaseTokenReservations($this->reservationToken, $this->seller->id, 'manual_release');
    expect(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(1)
        ->and(WarehouseStockService::available($this->centralWarehouse, $this->componentVariant->product_id, $this->componentVariant->id))->toBe(3);
});

it('does not allow invoicing two packages when only one physical package is in stock', function (): void {
    $line = array_merge($this->bundleLine, ['quantity' => 2]);
    expect(fn () => app(PreinvoiceDraftReservationService::class)
        ->syncReservationRows($this->reservationToken, $this->seller->id, [$line]))
        ->toThrow(ValidationException::class);

    expect(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(1)
        ->and(WarehouseStockService::available($this->centralWarehouse, $this->componentVariant->product_id, $this->componentVariant->id))->toBe(3);
});

it('saves and finalizes an invoice for one physical bundle without double-deducting stock', function (): void {
    $rows = [[
        'item_id' => null,
        'id' => $this->bundle->id,
        'product_id' => $this->bundle->id,
        'variety_id' => $this->bundleVariant->id,
        'variant_id' => $this->bundleVariant->id,
        'quantity' => 1,
        'price' => 1000,
        'line_discount_amount' => 0,
    ]];

    app(PreinvoiceDraftReservationService::class)->syncReservationRows(
        $this->reservationToken, $this->seller->id, [$this->bundleLine]
    );
    expect(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(0);

    $this->postJson(route('preinvoice.autosave'), [
        'reservation_token' => $this->reservationToken,
        'customer_name' => 'Bundle QA customer',
        'customer_mobile' => '09120000000',
        'products' => $rows,
    ])->assertOk();

    $this->post(route('preinvoice.draft.save'), largePayloadPost($rows, [
        'reservation_token' => $this->reservationToken,
    ]))->assertSessionHasNoErrors();

    $order = PreinvoiceOrder::query()
        ->where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE)->firstOrFail();
    expect($order->items()->count())->toBe(1)
        ->and($order->items()->first()->variant_id)->toBe($this->bundleVariant->id);

    $this->post(route('preinvoice.draft.finalize', $order->uuid), [
        'finance_version' => FinanceDocumentVersion::token($order->fresh('items')),
    ])->assertSessionHasNoErrors();

    $invoice = Invoice::query()->where('preinvoice_order_id', $order->id)->firstOrFail();
    expect($invoice->items()->count())->toBe(1)
        ->and((int) $invoice->items()->first()->variant_id)->toBe($this->bundleVariant->id)
        ->and((int) $invoice->items()->first()->quantity)->toBe(1)
        ->and(WarehouseStockService::available($this->centralWarehouse, $this->bundle->id, $this->bundleVariant->id))->toBe(0)
        ->and(WarehouseStockService::available($this->centralWarehouse, $this->componentVariant->product_id, $this->componentVariant->id))->toBe(3);
});
