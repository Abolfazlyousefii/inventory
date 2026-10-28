<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseTransfer;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
function showroomFixture(): array
{
    $admin = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $central = WarehouseStockService::centralWarehouseId();
    $showroom = Warehouse::where('type', 'showroom')->firstOrFail();
    $category = Category::create(['name' => 'Showroom test']);
    $product = Product::withoutEvents(fn () => Product::create(['name' => 'Cable', 'sku' => Str::uuid(), 'category_id' => $category->id, 'stock' => 10, 'price' => 1000, 'is_sellable' => true]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $product->id, 'variant_name' => 'Black', 'variant_code' => Str::uuid(), 'stock' => 10, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true]));
    WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 10]));
    $payload = ['request_token' => (string) Str::uuid(), 'from_warehouse_id' => $central, 'to_warehouse_id' => $showroom->id, 'items' => [['category_id' => $category->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3]]];

    return compact('admin', 'central', 'showroom', 'category', 'product', 'variant', 'payload');
}
it('creates an independent active showroom and shows its voucher entry', function () {
    extract(showroomFixture());
    $this->actingAs($admin)->get(route('vouchers.index'))->assertOk()->assertSee('حواله شوروم');
    $this->get(route('vouchers.section.create', 'showroom'))->assertOk()->assertSee('انبار شوروم');
    expect($showroom->is_active)->toBeTrue();
});
it('moves central stock to showroom once and keeps commercial balances unchanged', function () {
    extract(showroomFixture());
    $this->actingAs($admin);
    $ledgers = DB::table('customer_ledgers')->count();
    foreach ([1, 2] as $retry) {
        $this->post(route('vouchers.section.store', 'showroom'), $payload)->assertRedirect(route('vouchers.section.index', 'showroom'));
    }
    expect(WarehouseTransfer::where('voucher_type', 'showroom')->count())->toBe(1)->and($variant->fresh()->stock)->toBe(7)->and(WarehouseStockService::available($showroom->id, $product->id, $variant->id))->toBe(3)->and(DB::table('customer_ledgers')->count())->toBe($ledgers);
    $this->get(route('vouchers.section.index','showroom'))->assertOk()->assertSee('موجودی فعلی شوروم')->assertSee('Cable');
});
it('rolls back every line on insufficient stock', function () {
    extract(showroomFixture());
    $payload['items'][] = $payload['items'][0];
    $payload['items'][1]['quantity'] = 9;
    $this->actingAs($admin)->postJson(route('vouchers.section.store', 'showroom'), $payload)->assertStatus(422);
    expect($variant->fresh()->stock)->toBe(10)->and(WarehouseTransfer::where('voucher_type', 'showroom')->count())->toBe(0)->and(DB::table('warehouse_transfer_submissions')->count())->toBe(0);
});
it('rejects a forged showroom destination', function () {
    extract(showroomFixture());
    $payload['to_warehouse_id'] = Warehouse::where('type', 'return')->value('id');
    $this->actingAs($admin)->postJson(route('vouchers.section.store', 'showroom'), $payload)->assertStatus(422);
    expect($variant->fresh()->stock)->toBe(10);
});
it('honours the central inventory count freeze', function () {
    extract(showroomFixture());
    DB::table('inventory_count_freezes')->where('id', 1)->update(['enabled' => 1, 'all_products' => 1]);
    $this->actingAs($admin)->postJson(route('vouchers.section.store', 'showroom'), $payload)->assertStatus(422);
    expect($variant->fresh()->stock)->toBe(10)->and(WarehouseTransfer::where('voucher_type', 'showroom')->count())->toBe(0);
});
it('keeps showroom access inside the existing warehouse permissions', function () {
    extract(showroomFixture());
    $this->actingAs(User::factory()->create(['is_active' => true, 'can_access_erp' => true]))->get(route('vouchers.section.create', 'showroom'))->assertForbidden();
});
it('rejects reuse of a submission token with a different quantity', function () {
    extract(showroomFixture());
    $this->actingAs($admin)->post(route('vouchers.section.store', 'showroom'), $payload)->assertRedirect();
    $payload['items'][0]['quantity'] = 5;
    $this->postJson(route('vouchers.section.store', 'showroom'), $payload)->assertStatus(409);
    expect($variant->fresh()->stock)->toBe(7)->and(WarehouseTransfer::where('voucher_type','showroom')->count())->toBe(1);
});

it('exports only central saleable stock after a showroom transfer',function(){
    extract(showroomFixture());$this->actingAs($admin)->post(route('vouchers.section.store','showroom'),$payload)->assertRedirect();
    $exporter=new class extends \App\Services\Sync\InventoryProductsSyncService {public function payload($products):array{return $this->buildPayload($products);}};
    $export=$exporter->payload(collect([$product->fresh()->load('variants')]));
    expect($export['products'][0]['variants'][0]['stock'])->toBe(7);
});
