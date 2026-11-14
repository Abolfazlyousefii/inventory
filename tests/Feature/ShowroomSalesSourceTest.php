<?php

use App\Http\Controllers\PreinvoiceController;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceReservationService;
use App\Services\ReservationQueryService;
use App\Services\SalesHavalehService;
use App\Services\SalesPrintDocumentService;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use App\Support\FinanceDocumentVersion;
use App\Support\ReservationSideEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/LargePayloadFixtures.php';

it('prints the customer copy with an explicit warning when optional bank details cannot be decrypted',function(){
    app()->instance(App\Services\CustomerInvoicePaymentSettingsService::class,Mockery::mock(App\Services\CustomerInvoicePaymentSettingsService::class,function($m){
        $m->shouldReceive('details')->once()->andThrow(new Illuminate\Contracts\Encryption\DecryptException('Invalid MAC'));
    }));
    $invoice=Invoice::create(['uuid'=>(string)Str::uuid(),'status'=>Invoice::STATUS_PENDING_COLLECTION,'subtotal'=>1000,'total'=>1000]);
    $invoice->items()->create(array_merge($this->line,['quantity'=>1,'price'=>1000]));
    $data=app(SalesPrintDocumentService::class)->invoiceData($invoice,'customer');
    expect($data['customerPayment'])->toBeNull()->and($data['paymentWarning'])->toContain('اطلاعات واریز');
    expect(view('prints.invoice',['printData'=>$data])->render())->toContain('اطلاعات واریز');
});

it('checks frozen preinvoice quantity increases against the original showroom', function () {
    $old = array_merge($this->line, ['quantity' => 1]);
    $new = array_merge($this->line, ['quantity' => 2]);
    $guard = new ReflectionMethod(PreinvoiceController::class, 'assertCentralStockForPositiveDeltas');
    $guard->invoke(app(PreinvoiceController::class), [$old], [$new]);
    expect(WarehouseStockService::available($this->central, $this->product->id, $this->variant->id))->toBe(0);
});

it('increases an existing showroom invoice line without consuming central stock', function () {
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    $invoice = Invoice::create(['uuid' => (string) Str::uuid(), 'created_by' => $this->seller->id,
        'status' => Invoice::STATUS_PENDING_COLLECTION, 'subtotal' => 1000, 'total' => 1000]);
    $item = $invoice->items()->create(array_merge($this->line, ['quantity' => 1, 'price' => 1000]));
    app(SalesHavalehService::class)->updateItemsForInvoice($invoice,
        [['id' => $item->id, 'quantity' => 2, 'price' => 1000]], $this->seller->id, 'finance_correction', 'آزمون افزایش تعداد');
    expect($item->fresh()->quantity)->toBe(2)
        ->and(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(4)
        ->and(WarehouseStockService::available($this->central, $this->product->id, $this->variant->id))->toBe(0);
});

beforeEach(function () {
    foreach (['product_bundle_components' => 'quantity', 'bundle_component_allocations' => 'remaining'] as $table => $quantityColumn) {
        if (! Schema::hasTable($table)) {
            Schema::create($table, function ($t) use ($quantityColumn) {
                $t->id();
                $t->integer('bundle_variant_id');
                $t->integer('component_variant_id');
                $t->integer($quantityColumn);
            });
        }
    }
    $this->seller = User::factory()->create();
    $this->actingAs($this->seller);
    $category = Category::withoutEvents(fn () => Category::create(['name' => 'QA showroom']));
    $this->product = Product::withoutEvents(fn () => Product::create(['name' => 'QA showroom item', 'sku' => 'QA-SHOW', 'category_id' => $category->id, 'stock' => 0, 'reserved' => 0, 'price' => 1000, 'is_sellable' => true]));
    $this->variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $this->product->id, 'variant_name' => 'QA', 'variant_code' => 'QA-SHOW', 'is_active' => true, 'sales_enabled' => true, 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0]));
    $this->central = WarehouseStockService::centralWarehouseId();
    $this->showroom = Warehouse::firstOrCreate(['name' => 'انبار شوروم', 'type' => 'showroom'], ['is_active' => true]);
    WarehouseStockService::set($this->central, $this->product->id, $this->variant->id, 0);
    WarehouseStockService::set($this->showroom->id, $this->product->id, $this->variant->id, 5);
    $this->token = (string) Str::uuid();
    $this->line = ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 3, 'source_warehouse_id' => $this->showroom->id];
});

it('keeps warehouse item corrections on the original showroom source', function (string $path) {
    Illuminate\Support\Facades\Http::fake();
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    $invoice = Invoice::create(['uuid'=>(string) Str::uuid(),'created_by'=>$this->seller->id,
        'status'=>Invoice::STATUS_COLLECTING,'subtotal'=>1000,'total'=>1000]);
    $item = $invoice->items()->create(array_merge($this->line,['quantity'=>1,'price'=>1000]));
    $service = app(App\Services\WarehouseCollectionService::class);
    $rows = [['id'=>$item->id,'product_id'=>$this->product->id,'variant_id'=>$this->variant->id,'quantity'=>2,'price'=>1000]];
    if ($path==='collection') {
        $service->updateCollectedItems($invoice,$rows,$this->seller,'آزمون شوروم',false,'invoice_correction');
    } else {
        $service->updateInvoiceItemsInPlace($invoice,$rows,$this->seller,false,'invoice_correction','آزمون شوروم');
    }
    expect($item->fresh()->source_warehouse_id)->toBe($this->showroom->id)
        ->and($item->fresh()->quantity)->toBe(2)
        ->and(WarehouseStockService::available($this->showroom->id,$this->product->id,$this->variant->id))->toBe(4)
        ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(0);
})->with(['collection','invoice']);

it('reserves and releases only showroom stock and keeps central reserved projections separate', function () {
    $service = app(PreinvoiceDraftReservationService::class);
    $service->syncReservationRows($this->token, $this->seller->id, [$this->line]);
    expect(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(2)
        ->and(WarehouseStockService::available($this->central, $this->product->id, $this->variant->id))->toBe(0)
        ->and(PreinvoiceDraftReservation::sole()->source_warehouse_id)->toBe($this->showroom->id)
        ->and((int) app(ReservationQueryService::class)->quantitiesByVariant()->get($this->variant->id, 0))->toBe(0);
    $service->releaseTokenReservations($this->token, $this->seller->id, 'manual_release');
    $service->releaseTokenReservations($this->token, $this->seller->id, 'manual_release');
    expect(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(5)
        ->and($this->variant->fresh()->stock)->toBe(0);
});

it('allows showroom independently when central also has free stock', function () {
    WarehouseStockService::set($this->central, $this->product->id, $this->variant->id, 1);
    $this->line['quantity'] = 1;
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token, $this->seller->id, [$this->line]);
    expect(WarehouseStockService::available($this->central, $this->product->id, $this->variant->id))->toBe(1)
        ->and(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(4)
        ->and(PreinvoiceDraftReservation::count())->toBe(1);
});

it('submits the same variant from two warehouses without merging stocks or invoice rows', function () {
    $this->withoutVite();
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    WarehouseStockService::set($this->central,$this->product->id,$this->variant->id,8);
    $rows = [
        ['id'=>$this->product->id,'variety_id'=>$this->variant->id,'quantity'=>3,'price'=>1000,'source_warehouse_id'=>null],
        ['id'=>$this->product->id,'variety_id'=>$this->variant->id,'quantity'=>2,'price'=>1000,'source_warehouse_id'=>$this->showroom->id],
    ];
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token,$this->seller->id,
        collect($rows)->map(fn($r)=>['product_id'=>$r['id'],'variant_id'=>$r['variety_id'],'quantity'=>$r['quantity'],'source_warehouse_id'=>$r['source_warehouse_id']])->all());
    expect(PreinvoiceDraftReservation::count())->toBe(2);
    $this->postJson(route('preinvoice.autosave'),['reservation_token'=>$this->token,'customer_name'=>'مشتری تست','customer_mobile'=>'09120000000','products'=>$rows])->assertOk();
    $this->post(route('preinvoice.draft.save'),largePayloadPost($rows,['reservation_token'=>$this->token]))->assertSessionHasNoErrors();
    $order = PreinvoiceOrder::where('status',PreinvoiceOrder::STATUS_PENDING_FINANCE)->firstOrFail();
    expect($order->items()->count())->toBe(2);
    $this->post(route('preinvoice.draft.finalize',$order->uuid),['finance_version'=>FinanceDocumentVersion::token($order->fresh('items'))])->assertSessionHasNoErrors();
    $invoice = Invoice::where('preinvoice_order_id',$order->id)->firstOrFail();
    expect($invoice->items()->count())->toBe(2)
        ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(5)
        ->and(WarehouseStockService::available($this->showroom->id,$this->product->id,$this->variant->id))->toBe(3);
    foreach (['customer','warehouse'] as $mode) {
        $data = app(SalesPrintDocumentService::class)->invoiceData($invoice->fresh('items'),$mode);
        expect($data['items']->pluck('warehouseLabel')->all())->toBe(['انبار مرکزی','انبار شوروم']);
        expect(view('prints.invoice',['printData'=>$data])->render())->toContain('شوروم')->not->toContain('انبار مرکزی');
    }
});

it('edits one of two same variant warehouse rows without merging or using the other stock',function(string $path) {
    Illuminate\Support\Facades\Http::fake();
    $this->seller->assignRole(Role::findOrCreate('Owner','web'));
    WarehouseStockService::set($this->central,$this->product->id,$this->variant->id,5);
    WarehouseStockService::set($this->showroom->id,$this->product->id,$this->variant->id,3);
    $invoice=Invoice::create(['uuid'=>(string)Str::uuid(),'created_by'=>$this->seller->id,'status'=>Invoice::STATUS_COLLECTING,'subtotal'=>5000,'total'=>5000]);
    $a=$invoice->items()->create(array_merge($this->line,['source_warehouse_id'=>null,'quantity'=>3,'price'=>1000]));
    $b=$invoice->items()->create(array_merge($this->line,['quantity'=>2,'price'=>1000]));
    $rows=[['id'=>$a->id,'product_id'=>$a->product_id,'variant_id'=>$a->variant_id,'quantity'=>3,'price'=>1000],['id'=>$b->id,'product_id'=>$b->product_id,'variant_id'=>$b->variant_id,'quantity'=>3,'price'=>1000]];
    if($path==='sales') app(SalesHavalehService::class)->updateItemsForInvoice($invoice,$rows,$this->seller->id,'finance_correction','تست');
    elseif($path==='collection') app(App\Services\WarehouseCollectionService::class)->updateCollectedItems($invoice,$rows,$this->seller,'تست',false,'invoice_correction');
    else app(App\Services\WarehouseCollectionService::class)->updateInvoiceItemsInPlace($invoice,$rows,$this->seller,false,'invoice_correction','تست');
    expect($invoice->items()->count())->toBe(2)
        ->and($a->fresh()->quantity)->toBe(3)->and($b->fresh()->quantity)->toBe(3)
        ->and(WarehouseStockService::available($this->central,$a->product_id,$a->variant_id))->toBe(5)
        ->and(WarehouseStockService::available($this->showroom->id,$a->product_id,$a->variant_id))->toBe(2);
})->with(['sales','collection','invoice']);

it('rejects shortages and arbitrary other warehouse sources', function () {
    $this->line['quantity'] = 6;
    try {
        app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token, $this->seller->id, [$this->line]);
        $this->fail('Shortage accepted');
    } catch (ValidationException) {
    }
    $other = Warehouse::create(['name' => 'انبار مرجوعی', 'type' => 'return', 'is_active' => true]);
    $this->line['source_warehouse_id'] = $other->id;
    $this->line['quantity'] = 1;
    try {
        app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token, $this->seller->id, [$this->line]);
        $this->fail('Invalid warehouse accepted');
    } catch (ValidationException) {
    }
    expect(PreinvoiceDraftReservation::count())->toBe(0);
});

it('persists showroom through autosave submission finance conversion and both print copies', function () {
    $this->withoutVite();
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    $rows = [['id' => $this->product->id, 'variety_id' => $this->variant->id, 'quantity' => 2, 'price' => 1000, 'source_warehouse_id' => $this->showroom->id]];
    $payload = largePayloadPost($rows, ['reservation_token' => $this->token]);
    $saved = $this->postJson(route('preinvoice.autosave'), ['reservation_token' => $this->token, 'customer_name' => 'مشتری تست', 'customer_mobile' => '09120000000', 'products' => $rows])->assertOk()->json();
    $draft = PreinvoiceOrder::where('uuid', $saved['uuid'])->firstOrFail();
    expect($draft->items()->first()->source_warehouse_id)->toBe($this->showroom->id);
    $this->post(route('preinvoice.draft.save'), $payload)->assertSessionHasNoErrors();
    $order = PreinvoiceOrder::where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE)->firstOrFail();
    expect($order->items()->first()->source_warehouse_id)->toBe($this->showroom->id)
        ->and(PreinvoiceDraftReservation::where('preinvoice_order_id', $order->id)->first()->source_warehouse_id)->toBe($this->showroom->id)
        ->and(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(3);
    $this->post(route('preinvoice.draft.finalize', $order->uuid), ['finance_version' => FinanceDocumentVersion::token($order->fresh('items'))])->assertSessionHasNoErrors();
    $invoice = Invoice::where('preinvoice_order_id', $order->id)->firstOrFail();
    expect($invoice->items()->first()->source_warehouse_id)->toBe($this->showroom->id);
    $this->get(route('vouchers.sales.edit', $invoice->uuid))->assertOk()->assertSee('تحویل از انبار شوروم');
    $this->get(route('preinvoice.my.show', $order->uuid))->assertOk()->assertSee('تحویل از انبار شوروم');
    $this->getJson(route('vouchers.sales.queue.data'))->assertOk()->assertJsonPath('rows.0.has_showroom_items', true);
    foreach (['customer', 'warehouse'] as $mode) {
        $data = app(SalesPrintDocumentService::class)->invoiceData($invoice->fresh('items'), $mode);
        expect(view('prints.invoice', ['printData' => $data])->render())->toContain('شوروم')->not->toContain('انبار مرکزی');
    }
    $line = app(WarehouseInboundService::class)->invoiceItemLine($invoice->items()->first(), 1);
    expect($line['suggested_warehouse_id'])->toBe($this->showroom->id)
        ->and(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(3)
        ->and($this->variant->fresh()->stock)->toBe(0);
    app(SalesHavalehService::class)->cancelAndRestore($invoice, 'لغو تست', $this->seller->id);
    $receipt = WarehouseInboundReceipt::where('source_type', 'invoice_cancel')->where('source_id', $invoice->id)->firstOrFail();
    expect($receipt->items()->first()->suggested_warehouse_id)->toBe($this->showroom->id);
    // Physical invoice cancellation keeps the existing inbound confirmation workflow.
    expect(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(3);
});

it('makes showroom-only products discoverable with separate stock fields', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->seller->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'QA showroom', 'in_stock_only' => 1]))->assertOk()->assertJsonPath('data.0.id', $this->product->id);
    $this->getJson('/preinvoice/api/products/'.$this->product->id)->assertOk()
        ->assertJsonPath('data.product.varieties.0.free_stock', 0)
        ->assertJsonPath('data.product.varieties.0.showroom_free_stock', 5)
        ->assertJsonPath('data.product.varieties.0.showroom_warehouse_id', $this->showroom->id);
});

it('keeps mixed source reservations separate through quantity reduction and expiry', function () {
    $order = PreinvoiceOrder::create(['uuid' => 'QA-SHOWROOM', 'created_by' => $this->seller->id,
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE, 'customer_name' => 'تست',
        'customer_mobile' => '09120000000', 'stock_frozen_until' => now()->addDay()]);
    $item = $order->items()->create(['product_id' => $this->product->id, 'variant_id' => $this->variant->id,
        'source_warehouse_id' => $this->showroom->id, 'quantity' => 3, 'price' => 1000]);
    $centralVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $this->product->id, 'variant_name' => 'Central', 'variant_code' => 'QA-CENTRAL', 'is_active' => true, 'sales_enabled' => true, 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0]));
    WarehouseStockService::set($this->central, $this->product->id, $centralVariant->id, 4);
    $order->items()->create(['product_id' => $this->product->id, 'variant_id' => $centralVariant->id, 'quantity' => 2, 'price' => 1000]);
    ReservationSideEffects::transaction(fn () => app(PreinvoiceController::class)->syncPreinvoiceReservations($order->fresh('items')));
    ReservationSideEffects::transaction(fn () => app(PreinvoiceReservationService::class)->adjustOfficialReservationDelta($order, $item, -1, $this->seller));
    $item->update(['quantity' => 2]);
    expect(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(3);
    expect(WarehouseStockService::available($this->central, $this->product->id, $centralVariant->id))->toBe(2);
    app(PreinvoiceReservationService::class)->expirePreinvoiceReservations($order, $this->seller);
    app(PreinvoiceReservationService::class)->expirePreinvoiceReservations($order, $this->seller);
    expect(WarehouseStockService::available($this->showroom->id, $this->product->id, $this->variant->id))->toBe(5)
        ->and($this->variant->fresh()->stock)->toBe(0)
        ->and(WarehouseStockService::available($this->central,$this->product->id,$centralVariant->id))->toBe(4);
});
