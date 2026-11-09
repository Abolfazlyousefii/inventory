<?php

use App\Models\{Category, Product, ProductVariant, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($user);
    $this->warehouses = collect(['central'=>'مرکزی', 'return'=>'مرجوعی', 'personnel'=>'پرسنل', 'scrap'=>'ضایعات', 'showroom'=>'شوروم'])
        ->map(fn ($name, $type) => Warehouse::firstOrCreate(['type'=>$type, 'parent_id'=>null], ['name'=>'انبار '.$name, 'is_active'=>true]));
    $this->product = Product::withoutEvents(fn () => Product::create([
        'category_id'=>Category::create(['name'=>'آزمایش موجودی'])->id, 'name'=>'کالای نمونه',
        'sku'=>'STOCK-'.Str::uuid(), 'stock'=>777, 'reserved'=>0, 'price'=>1000, 'is_sellable'=>true,
    ]));
    $this->variant = ProductVariant::create(['product_id'=>$this->product->id, 'variant_name'=>'سفید',
        'variant_code'=>'STOCK-WHITE', 'stock'=>777, 'reserved'=>0, 'sell_price'=>1000, 'is_active'=>true, 'sales_enabled'=>true]);
});

function overviewStock($test, Warehouse $warehouse, int $quantity, ?int $variantId = null): void
{
    WarehouseStock::create(['warehouse_id'=>$warehouse->id, 'product_id'=>$test->product->id,
        'product_variant_id'=>$variantId ?? $test->variant->id, 'quantity'=>$quantity]);
}

it('returns every main warehouse with explicit zeros even when no stock rows exist', function () {
    $before = WarehouseStock::count();
    $result = $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'variant_id'=>$this->variant->id]))
        ->assertOk()->assertJsonCount(5, 'rows')->assertJsonCount(5, 'variants.0.warehouses')
        ->assertJsonPath('summary.warehouses_count', 5)->assertJsonPath('summary.total_quantity', 0);
    expect(collect($result->json('rows'))->pluck('warehouse_id')->all())->toBe($this->warehouses->pluck('id')->values()->all());
    foreach ($result->json('rows') as $row) expect($row['total_quantity'])->toBe(0)->and($row['available_quantity'])->toBe(0);
    expect(WarehouseStock::count())->toBe($before)->and($this->variant->fresh()->stock)->toBe(777);
});

it('rolls up personnel stocks exactly once and includes zero and inactive personnel details', function () {
    $root = $this->warehouses['personnel'];
    $first = Warehouse::create(['name'=>'پرسنل اول', 'type'=>'personnel', 'parent_id'=>$root->id, 'is_active'=>true]);
    $second = Warehouse::create(['name'=>'پرسنل دوم', 'type'=>'personnel', 'parent_id'=>$root->id, 'is_active'=>false]);
    overviewStock($this, $root, 2);
    overviewStock($this, $first, 7);
    overviewStock($this, $this->warehouses['central'], 11);
    $result = $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'variant_id'=>$this->variant->id]))->assertOk()->assertJsonCount(5, 'rows');
    $personnel = collect($result->json('variants.0.warehouses'))->firstWhere('id', $root->id);
    expect($personnel['available_quantity'])->toBe(9)->and($personnel['total_quantity'])->toBe(9);
    $children = collect($personnel['children'])->keyBy('id');
    expect($children[$first->id]['available_quantity'])->toBe(7)->and($children[$second->id]['available_quantity'])->toBe(0)
        ->and($children[$root->id]['available_quantity'])->toBe(2)
        ->and($result->json('summary.available_quantity'))->toBe(20)
        ->and(collect($result->json('rows'))->sum('available_quantity'))->toBe(20);
});

it('includes deeper personnel warehouses without counting parent totals twice', function () {
    $parent = Warehouse::create(['name'=>'بخش پرسنل', 'type'=>'personnel', 'parent_id'=>$this->warehouses['personnel']->id, 'is_active'=>true]);
    $leaf = Warehouse::create(['name'=>'کارمند زیرمجموعه', 'type'=>'personnel', 'parent_id'=>$parent->id, 'is_active'=>true]);
    overviewStock($this, $parent, 3);
    overviewStock($this, $leaf, 4);
    $result = $this->getJson(route('products.warehouse-stock', $this->product))->assertOk()->assertJsonCount(5, 'rows');
    $personnel = collect($result->json('rows'))->firstWhere('warehouse_id', $this->warehouses['personnel']->id);
    expect($personnel['available_quantity'])->toBe(7)->and(collect($personnel['children'])->sum('available_quantity'))->toBe(7);
});

it('supports products without variants and retains zero and negative stock rows', function () {
    $this->variant->delete();
    WarehouseStock::create(['warehouse_id'=>$this->warehouses['return']->id, 'product_id'=>$this->product->id, 'product_variant_id'=>null, 'quantity'=>-2]);
    $result = $this->getJson(route('products.warehouse-stock', $this->product))->assertOk()->assertJsonCount(5, 'rows');
    $returns = collect($result->json('rows'))->firstWhere('warehouse_id', $this->warehouses['return']->id);
    expect($returns['available_quantity'])->toBe(-2)->and($result->json('summary.available_quantity'))->toBe(-2);
});

it('keeps each variant separate and preserves full zero rows during pagination and search', function () {
    $second = ProductVariant::create(['product_id'=>$this->product->id, 'variant_name'=>'مشکی', 'variant_code'=>'STOCK-Z', 'stock'=>0, 'sell_price'=>1000]);
    overviewStock($this, $this->warehouses['showroom'], 6, $second->id);
    $firstPage = $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'limit'=>1]))->assertOk()->assertJsonCount(5, 'rows');
    expect($firstPage->json('meta.has_more'))->toBeTrue();
    $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'limit'=>1, 'cursor'=>1]))->assertOk()
        ->assertJsonCount(5, 'rows')->assertJsonPath('variants.0.summary.available_quantity', 6);
    $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'q'=>'سفید']))->assertOk()
        ->assertJsonCount(5, 'rows')->assertJsonPath('variants.0.summary.available_quantity', 0);
    $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'q'=>'وجود ندارد']))->assertOk()->assertJsonCount(0, 'rows');
});

it('rejects a variant from another product and prevents unprivileged access', function () {
    $other = Product::withoutEvents(fn () => Product::create(['name'=>'دیگر', 'sku'=>'OTHER-'.Str::uuid(), 'category_id'=>$this->product->category_id, 'price'=>1000]));
    $this->getJson(route('products.warehouse-stock', ['product'=>$other, 'variant_id'=>$this->variant->id]))->assertUnprocessable();
    $this->actingAs(User::factory()->create())->getJson(route('products.warehouse-stock', $this->product))->assertForbidden();
});

it('allocates canonical reservations only to the central row and does not double count them in personnel details', function () {
    $this->mock(\App\Services\ReservationQueryService::class, function ($mock) {
        $mock->shouldReceive('quantitiesByVariant')->once()->with($this->product->id, [$this->variant->id])
            ->andReturn(collect([$this->variant->id=>3]));
    });
    $staff = Warehouse::create(['name'=>'کارمند', 'type'=>'personnel', 'parent_id'=>$this->warehouses['personnel']->id, 'is_active'=>true]);
    overviewStock($this, $this->warehouses['central'], 10);
    overviewStock($this, $staff, 4);
    $response = $this->getJson(route('products.warehouse-stock', ['product'=>$this->product, 'variant_id'=>$this->variant->id]))->assertOk();
    $rows = collect($response->json('rows'))->keyBy('warehouse_id');
    expect($rows[$this->warehouses['central']->id]['total_quantity'])->toBe(13)
        ->and($rows[$this->warehouses['central']->id]['reserved_quantity'])->toBe(3)
        ->and($rows[$this->warehouses['personnel']->id]['reserved_quantity'])->toBe(0)
        ->and($response->json('summary.total_quantity'))->toBe(17)
        ->and($rows->sum('total_quantity'))->toBe(17);
});
