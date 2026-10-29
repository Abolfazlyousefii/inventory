<?php

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\BundleComponentAllocationService;
use App\Services\HistoricalReservationArchiveService;
use App\Services\InventoryCountFreezeService;
use App\Services\PreinvoiceBundleStockService;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceReservationService;
use App\Services\StockCountDocumentService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
beforeEach(function () {
    Schema::table('products', function (Blueprint $t) {
        $t->boolean('is_bundle')->default(false);
        $t->string('bundle_stock_mode')->default('physical');
    });
    Schema::create('product_bundle_components', function (Blueprint $t) {
        $t->id();
        $t->integer('bundle_variant_id');
        $t->integer('component_variant_id');
        $t->integer('quantity');
        $t->timestamps();
    });
    Schema::create('bundle_component_allocations', function (Blueprint $t) {
        $t->id();
        $t->integer('bundle_variant_id');
        $t->integer('component_variant_id');
        $t->integer('remaining');
        $t->timestamps();
    });
});

function freezeFixture(): array
{
    $category = Category::create(['name' => 'Gadgets '.Str::uuid()]);
    $child = Category::create(['name' => 'Cable '.Str::uuid(), 'parent_id' => $category->id]);
    $product = Product::withoutEvents(fn () => Product::create(['name' => 'Cable', 'sku' => Str::uuid(), 'category_id' => $child->id, 'stock' => 10, 'reserved' => 0, 'price' => 1000, 'is_sellable' => true]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $product->id, 'variant_name' => 'Black', 'variant_code' => Str::uuid(), 'stock' => 10, 'reserved' => 0, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true]));
    WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 10]));
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));

    return compact('category', 'child', 'product', 'variant', 'admin');
}

it('freezes descendants and rejects central movements but permits other products and stocktake', function () {
    extract(freezeFixture());
    $service = app(InventoryCountFreezeService::class);
    $service->activate([$category->id], false, $admin);
    expect($service->blocked($product->id))->toBeTrue();
    try {
        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $product->id, -1, $variant->id);
        test()->fail('Blocked movement succeeded');
    } catch (ValidationException $e) {
    }
    expect($variant->fresh()->stock)->toBe(10);
    $service->forStocktake(fn () => WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id, 8));
    expect($variant->fresh()->stock)->toBe(8);
    $service->deactivate($admin);
    WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $product->id, -1, $variant->id);
    expect($variant->fresh()->stock)->toBe(7);
});

it('blocks both central stock additions and direct quantity setting during a category count', function () {
    extract(freezeFixture());
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    foreach ([
        fn () => WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $product->id, 3, $variant->id),
        fn () => WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id, 13),
    ] as $operation) {
        try {
            $operation();
            test()->fail('A central stock increase bypassed the freeze');
        } catch (ValidationException $error) {
            expect($error->errors())->toHaveKey('stocktake');
        }
        expect($variant->fresh()->stock)->toBe(10)
            ->and((int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'))->toBe(10);
    }
});

it('keeps a sales return receipt pending and posts no stock or customer credit when central counting is active', function () {
    extract(freezeFixture());
    $customer = \App\Models\Customer::factory()->create();
    $document = \App\Models\SalesReturnDocument::create([
        'document_number' => 'FREEZE-RETURN', 'source_type' => 'sazeh_hesab', 'status' => 'pending_warehouse',
        'customer_id' => $customer->id, 'default_destination_warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'total_quantity' => 2, 'total_refund_amount' => 2000, 'items_count' => 1,
    ]);
    $document->items()->create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'item_source' => 'existing_product', 'item_condition' => 'healthy',
        'destination_warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'return_quantity' => 2, 'refund_unit_price' => 1000, 'refund_amount' => 2000,
    ]);
    $inbound = app(\App\Services\WarehouseInboundService::class);
    $receipt = $inbound->queueSalesReturn($document->fresh('items'), $admin->id);
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    try {
        $inbound->receive($receipt, $receipt->items->map(fn ($row) => [
            'id' => $row->id, 'accepted_quantity' => 2, 'received_warehouse_id' => WarehouseStockService::centralWarehouseId(),
        ])->all(), $admin->id, null, \App\Support\InboundReviewVersion::token($receipt));
        test()->fail('Warehouse accepted a counted product');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('stocktake');
    }
    expect($receipt->fresh()->status)->toBe('pending')
        ->and($document->fresh()->status)->toBe('pending_warehouse')
        ->and($variant->fresh()->stock)->toBe(10)
        ->and(\App\Models\StockMovement::count())->toBe(0)
        ->and(\App\Models\CustomerLedger::count())->toBe(0);
});

it('releases temporary reservations exactly once and prevents late heartbeat reserving again', function () {
    extract(freezeFixture());
    $seller = User::factory()->create();
    $token = (string) Str::uuid();
    $items = [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3]];
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($token, $seller->id, $items);
    expect($variant->fresh()->stock)->toBe(7);
    $service = app(InventoryCountFreezeService::class);
    $service->activate([$category->id], false, $admin);
    expect($variant->fresh()->stock)->toBe(10)->and($variant->fresh()->reserved)->toBe(0);
    try {
        $service->activate([$category->id], false, $admin);
        test()->fail('Repeated activation must require ending the prior count');
    } catch (ValidationException $e) {
    }
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($token, $seller->id, $items);
    expect($variant->fresh()->stock)->toBe(10)->and(PreinvoiceDraftReservation::whereNull('released_at')->count())->toBe(0);
});

it('allows only administrators to change freeze settings', function () {
    extract(freezeFixture());
    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('inventory-count-freeze.update'), ['enabled' => true, 'category_ids' => [$category->id]])->assertForbidden();
    expect(app(InventoryCountFreezeService::class)->blocked($product->id))->toBeFalse();
    $this->actingAs($admin)->get(route('inventory-count-freeze.index'))->assertOk();
});

it('leaves unrelated categories and noncentral warehouses operational', function () {
    $a = freezeFixture();
    $b = freezeFixture();
    $service = app(InventoryCountFreezeService::class);
    $service->activate([$a['category']->id], false, $a['admin']);
    expect($service->blocked($b['product']->id))->toBeFalse();
    WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $b['product']->id, -2, $b['variant']->id);
    $warehouse = Warehouse::create(['name' => 'مرجوعی', 'type' => 'return', 'is_active' => true]);
    WarehouseStockService::change($warehouse->id, $a['product']->id, 2, $a['variant']->id);
    expect($a['variant']->fresh()->stock)->toBe(10)->and($b['variant']->fresh()->stock)->toBe(8);
});

it('returns an entire mixed official preinvoice to draft with all items preserved', function () {
    $a = freezeFixture();
    $b = freezeFixture();
    $seller = User::factory()->create();
    $order = PreinvoiceOrder::create(['uuid' => 'FREEZE-MIX', 'customer_name' => 'Test', 'customer_mobile' => '09123456789', 'created_by' => $seller->id, 'seller_id' => $seller->id, 'status' => 'pending_finance', 'stock_frozen_until' => now()->addHours(2), 'total_price' => 5000]);
    foreach ([$a, $b] as $x) {
        $order->items()->create(['product_id' => $x['product']->id, 'variant_id' => $x['variant']->id, 'quantity' => 2, 'price' => 1000, 'line_total' => 2000]);
        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $x['product']->id, -2, $x['variant']->id);
        PreinvoiceDraftReservation::create(['token' => (string) Str::uuid(), 'user_id' => $seller->id, 'preinvoice_order_id' => $order->id, 'product_id' => $x['product']->id, 'variant_id' => $x['variant']->id, 'quantity' => 2, 'reservation_scope' => 'official', 'last_seen_at' => now(), 'expires_at' => now()->addHours(2)]);
    }
    $service = app(InventoryCountFreezeService::class);
    $service->activate([$a['category']->id], false, $a['admin']);
    expect($order->fresh()->status)->toBe('draft')->and($order->items()->count())->toBe(2)->and($a['variant']->fresh()->stock)->toBe(10)->and($b['variant']->fresh()->stock)->toBe(10);
    try {
        app(PreinvoiceReservationService::class)->assertFinanceApprovable($order->fresh(), $a['admin']);
        test()->fail('Finance bypassed count');
    } catch (ValidationException $e) {
    }
    $service->deactivate($a['admin']);
    expect($order->fresh()->status)->toBe('draft')->and(PreinvoiceDraftReservation::whereNull('released_at')->count())->toBe(0);
});

it('rolls back the whole activation if an invalid official reservation is found', function () {
    extract(freezeFixture());
    $seller = User::factory()->create();
    app(PreinvoiceDraftReservationService::class)->syncReservationRows((string) Str::uuid(), $seller->id, [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]]);
    $invalidOrder = PreinvoiceOrder::create(['uuid' => 'INVALID-FREEZE', 'customer_name' => 'Test', 'customer_mobile' => '09120000000', 'created_by' => $seller->id, 'status' => 'cancelled_by_warehouse', 'total_price' => 1000]);
    PreinvoiceDraftReservation::create(['token' => (string) Str::uuid(), 'user_id' => $seller->id, 'preinvoice_order_id' => $invalidOrder->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 7, 'reservation_scope' => 'official', 'last_seen_at' => now(), 'expires_at' => now()->addHour()]);
    $before = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();
    try {
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        test()->fail('Invalid official reservation must stop activation');
    } catch (ValidationException $e) {
    }
    expect($variant->fresh()->stock)->toBe(8)->and(app(InventoryCountFreezeService::class)->blocked($product->id))->toBeFalse()->and(DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson())->toBe($before);
});

it('archives ambiguous historical rows without inventing available stock', function () {
    extract(freezeFixture());
    $seller = User::factory()->create();
    app(PreinvoiceDraftReservationService::class)->syncReservationRows((string) Str::uuid(), $seller->id, [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]]);
    $history = PreinvoiceDraftReservation::create(['token' => (string) Str::uuid(), 'user_id' => $seller->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 7, 'reservation_scope' => 'temporary_online', 'last_seen_at' => now()->subDays(100), 'expires_at' => now()->subDays(100)]);
    $result = app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    expect($variant->fresh()->stock)->toBe(10)->and($result['released_quantity'])->toBe(2)->and($result['archived_rows'])->toBe(1)->and($history->fresh()->release_reason)->toBe(HistoricalReservationArchiveService::RELEASE_REASON);
});

it('never restores reservations already consumed by invoices', function () {
    extract(freezeFixture());
    $seller = User::factory()->create();
    PreinvoiceDraftReservation::create(['token' => (string) Str::uuid(), 'user_id' => $seller->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 4, 'reservation_scope' => 'official', 'converted_at' => now(), 'release_reason' => 'consumed']);
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    expect($variant->fresh()->stock)->toBe(10)->and(PreinvoiceDraftReservation::first()->release_reason)->toBe('consumed');
});

it('permits the real stocktake finalize workflow during a freeze', function () {
    extract(freezeFixture());
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    $service = app(StockCountDocumentService::class);
    $doc = $service->createProductDraft(['product_id' => $product->id, 'document_date' => '2026-10-28', 'actual_quantities' => [$variant->id => 6]], $admin->id);
    $service->finalize($doc, $admin->id, true);
    expect($variant->fresh()->stock)->toBe(6)->and($doc->fresh()->status)->toBe('finalized')->and(app(InventoryCountFreezeService::class)->blocked($product->id))->toBeTrue();
});

it('can count a zero-stock variant with no central warehouse row yet', function () {
    extract(freezeFixture());
    WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->delete());
    DB::table('product_variants')->where('id', $variant->id)->update(['stock' => 0]);
    DB::table('products')->where('id', $product->id)->update(['stock' => 0]);
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    $service = app(StockCountDocumentService::class);
    $doc = $service->createProductDraft(['product_id' => $product->id, 'document_date' => '2026-10-28', 'actual_quantities' => [$variant->id => 5]], $admin->id);
    $service->finalize($doc, $admin->id, true);
    expect($variant->fresh()->stock)->toBe(5);
});

it('prevents a direct stock model write and rejects unauthorized service calls', function () {
    extract(freezeFixture());
    $service = app(InventoryCountFreezeService::class);
    $service->activate([], true, $admin);
    try {
        WarehouseStock::first()->update(['quantity' => 99]);
        test()->fail('Direct model write bypassed freeze');
    } catch (ValidationException $e) {
    }
    expect($variant->fresh()->stock)->toBe(10);
    try {
        $service->deactivate(User::factory()->create());
        test()->fail('Non admin changed freeze');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

it('releases remaining bundle allocations and closes indirect bundle selling', function () {
    extract(freezeFixture());
    $bundle = Product::withoutEvents(fn () => Product::create(['name' => 'Kit', 'sku' => Str::uuid(), 'category_id' => $category->id, 'is_bundle' => true, 'bundle_stock_mode' => 'automatic', 'stock' => 0, 'reserved' => 0, 'price' => 1000, 'is_sellable' => true]));
    $bv = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $bundle->id, 'variant_name' => 'Kit', 'variant_code' => Str::uuid(), 'stock' => 0, 'reserved' => 0, 'is_active' => true, 'sales_enabled' => true]));
    DB::table('product_bundle_components')->insert(['bundle_variant_id' => $bv->id, 'component_variant_id' => $variant->id, 'quantity' => 1]);
    app(BundleComponentAllocationService::class)->setAllocations($bv->id, [$variant->id => 4]);
    expect($variant->fresh()->stock)->toBe(6);
    app(InventoryCountFreezeService::class)->activate([$child->id], false, $admin);
    expect($variant->fresh()->stock)->toBe(10)->and(app(InventoryCountFreezeService::class)->blocked($bundle->id))->toBeTrue()->and((int) DB::table('bundle_component_allocations')->value('remaining'))->toBe(0);
    try {
        app(PreinvoiceBundleStockService::class)->reserve($bundle->id, $bv->id, 1);
        test()->fail('Indirect bundle sale bypassed freeze');
    } catch (ValidationException $e) {
    }
});

it('stops activation on active site holds without cancelling a site order', function () {
    extract(freezeFixture());
    Schema::create('site_stock_holds', function (Blueprint $t) {
        $t->id();
        $t->integer('site_order_id');
        $t->string('status');
        $t->timestamps();
    });
    Schema::create('site_stock_hold_lines', function (Blueprint $t) {
        $t->id();
        $t->integer('hold_id');
        $t->integer('product_id');
        $t->integer('variant_id');
        $t->integer('quantity');
    });
    DB::table('site_stock_holds')->insert(['id' => 1, 'site_order_id' => 123, 'status' => 'payment_confirmed']);
    DB::table('site_stock_hold_lines')->insert(['hold_id' => 1, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]);
    try {
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        test()->fail('Active site hold was discarded');
    } catch (ValidationException $e) {
    }
    expect($variant->fresh()->stock)->toBe(10)->and(app(InventoryCountFreezeService::class)->blocked($product->id))->toBeFalse()->and(DB::table('site_stock_holds')->value('status'))->toBe('payment_confirmed');
});

it('rejects an old administrator page trying to end a newer count', function () {
    extract(freezeFixture());
    $service = app(InventoryCountFreezeService::class);
    $service->activate([$category->id], false, $admin, 0);
    $service->deactivate($admin, 1);
    $service->activate([$category->id], false, $admin, 2);
    try {
        $service->deactivate($admin, 1);
        test()->fail('Stale page ended a newer count');
    } catch (ValidationException $e) {
    }
    expect($service->blocked($product->id))->toBeTrue();
});

it('previews and activates through the administrator form and returns a readable status', function () {
    extract(freezeFixture());
    $this->actingAs($admin);
    $this->postJson(route('inventory-count-freeze.preview'), ['category_ids' => [$category->id]])->assertOk()->assertJsonPath('products', 1);
    $this->post(route('inventory-count-freeze.update'), ['enabled' => 1, 'version' => 0, 'acknowledge' => 1, 'category_ids' => [$category->id]])->assertRedirect(route('inventory-count-freeze.index'));
    $this->get(route('inventory-count-freeze.index'))->assertOk()->assertSee('انبارگردانی فعال است')->assertSee('پایان انبارگردانی');
    $this->post(route('inventory-count-freeze.update'), ['enabled' => 0, 'version' => 1])->assertRedirect();
    expect(app(InventoryCountFreezeService::class)->blocked($product->id))->toBeFalse();
});

it('saves a real draft without reserving counted products and rejects submission', function () {
    extract(freezeFixture());
    app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
    $this->actingAs($admin);
    $rows = [['id' => $product->id, 'product_id' => $product->id, 'variety_id' => $variant->id, 'variant_id' => $variant->id, 'quantity' => 2, 'price' => 1000, 'line_discount_amount' => 0]];
    $payload = ['intent' => 'draft', 'reservation_token' => (string) Str::uuid(), 'customer_name' => 'Freeze draft', 'customer_mobile' => '09120000000', 'is_in_person' => 0, 'discount_amount' => 0, 'products_payload' => json_encode($rows), 'products_payload_count' => 1, 'products_payload_version' => 1, 'products_payload_complete' => 1, 'products_payload_total_quantity' => 2, 'products_payload_gross_total' => 2000];
    $this->post(route('preinvoice.draft.save'), $payload)->assertSessionHasNoErrors();
    $order = PreinvoiceOrder::where('created_by', $admin->id)->latest('id')->firstOrFail();
    expect($order->status)->toBe('draft')->and($order->items()->count())->toBe(1)->and($variant->fresh()->stock)->toBe(10)->and(PreinvoiceDraftReservation::whereNull('released_at')->count())->toBe(0);
    $payload['intent'] = 'submit';
    $payload['autosave_uuid'] = $order->uuid;
    $this->postJson(route('preinvoice.draft.save'), $payload)->assertStatus(422);
    expect($variant->fresh()->stock)->toBe(10)->and($order->fresh()->status)->toBe('draft');
    app(InventoryCountFreezeService::class)->deactivate($admin);
    $this->post(route('preinvoice.draft.save'), $payload)->assertSessionHasNoErrors();
    expect($variant->fresh()->stock)->toBe(8)->and($order->fresh()->status)->toBe('pending_finance');
});
