<?php

namespace Tests\Feature;

use App\Models\{Category, Invoice, InvoiceItem, PreinvoiceDraftReservation, PreinvoiceOrder, Product, ProductVariant, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseStockFlowReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
    }

    private function stock(string $name = 'قاب آیفون', int $quantity = 200): array
    {
        $category = Category::withoutEvents(fn () => Category::create(['name'=>'Stock-flow '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::create([
            'category_id' => $category->id,
            'name' => $name, 'sku' => 'FLOW-'.Str::random(10),
            'price' => 300000, 'stock' => $quantity, 'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $product->id, 'variant_name' => 'مشکی',
            'variant_code' => 'FLOW-V-'.Str::random(10), 'stock' => $quantity,
            'is_active' => true, 'sales_enabled' => true, 'sell_price' => 300000,
        ]));
        $warehouse = Warehouse::firstOrCreate(['type'=>'central','name'=>'انبار مرکزی'], ['is_active'=>true]);
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,
            'product_variant_id'=>$variant->id,'quantity'=>$quantity,
        ]));
        return [$product, $variant, $warehouse];
    }

    private function invoice(Product $product, ProductVariant $variant, string $status, int $quantity): void
    {
        $invoice = Invoice::withoutEvents(fn () => Invoice::create([
            'uuid' => 'F-'.Str::random(12), 'status'=>$status,
            'customer_name'=>'مشتری تست','subtotal'=>300000,'total'=>300000,
        ]));
        InvoiceItem::withoutEvents(fn () => InvoiceItem::create([
            'invoice_id'=>$invoice->id,'product_id'=>$product->id,
            'variant_id'=>$variant->id,'quantity'=>$quantity,
            'price'=>300000,'line_total'=>$quantity*300000,
        ]));
    }

    public function test_uncollected_invoice_queues_are_separate_from_shipped_and_ready_to_ship(): void
    {
        $this->owner();
        [$product,$variant,$warehouse] = $this->stock();
        $this->invoice($product,$variant,Invoice::STATUS_PENDING_COLLECTION,100);
        $this->invoice($product,$variant,Invoice::STATUS_WAREHOUSE_RECEIVED,7);
        $this->invoice($product,$variant,Invoice::STATUS_COLLECTING,3);
        $this->invoice($product,$variant,Invoice::STATUS_READY_TO_SHIP,33);
        $this->invoice($product,$variant,Invoice::STATUS_SHIPPED,44);

        $before = DB::table('warehouse_stocks')->where('id',1)->value('quantity');
        $response = $this->getJson(route('warehouse.stock-flow.rows'))->assertOk();
        $row = collect($response->json('rows'))->firstWhere('variant_id',$variant->id);

        $this->assertNotNull($row);
        $this->assertSame(200,$row['available']);
        $this->assertSame(100,$row['pending_collection']);
        $this->assertSame(7,$row['warehouse_received']);
        $this->assertSame(3,$row['collecting']);
        $this->assertSame(110,$row['queue_total']);
        $this->assertSame(5,Invoice::count());
        $this->assertSame($before,DB::table('warehouse_stocks')->where('id',1)->value('quantity'));
        $this->assertSame(0,DB::table('stock_movements')->count());
    }

    public function test_official_preinvoice_reservations_are_separate_and_not_recounted_as_invoice_queue(): void
    {
        $this->owner();
        [$p,$v] = $this->stock('مدل موجود',50);
        $seller=User::factory()->create();
        $order=PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::create([
            'uuid'=>'PI-'.Str::random(8),'status'=>PreinvoiceOrder::STATUS_PENDING_FINANCE,
            'created_by'=>$seller->id,'customer_name'=>'Test','customer_mobile'=>'09120000000','total_price'=>1000,
        ]));
        PreinvoiceDraftReservation::create([
            'token'=>(string)Str::uuid(), 'user_id'=>$seller->id,
            'preinvoice_order_id'=>$order->id, 'reservation_scope'=>'official',
            'product_id'=>$p->id, 'variant_id'=>$v->id,
            'quantity'=>11,'expires_at'=>now()->addDay(),
        ]);
        $row=collect($this->getJson(route('warehouse.stock-flow.rows'))->assertOk()->json('rows'))->firstWhere('variant_id',$v->id);
        $this->assertSame(11,$row['official_reserved']);
        $this->assertSame(0,$row['queue_total']);

        $this->invoice($p,$v,Invoice::STATUS_PENDING_COLLECTION,8);
        // Once converted, an order carrying an invoice cannot be counted in official holds.
        $invoice=Invoice::latest('id')->firstOrFail();
        $invoice->forceFill(['preinvoice_order_id'=>$order->id])->saveQuietly();
        $row=collect($this->getJson(route('warehouse.stock-flow.rows'))->assertOk()->json('rows'))->firstWhere('variant_id',$v->id);
        $this->assertSame(0,$row['official_reserved']);
        $this->assertSame(8,$row['queue_total']);
    }

    public function test_cursor_and_filter_are_bounded_and_print_shell_is_read_only(): void
    {
        $this->owner();
        [$p1,$v1]=$this->stock('محصول اول',15);
        [$p2,$v2]=$this->stock('محصول دوم',20);
        $this->invoice($p2,$v2,Invoice::STATUS_PENDING_COLLECTION,2);
        $first=$this->getJson(route('warehouse.stock-flow.rows',['limit'=>1]))
            ->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('has_more',true);
        $second=$this->getJson(route('warehouse.stock-flow.rows',['after_id'=>$first->json('next_cursor'),'limit'=>1]))
            ->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('has_more',false);
        $this->getJson(route('warehouse.stock-flow.rows',['only_in_flow'=>1]))
            ->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('rows.0.variant_id',$v2->id);
        $this->getJson(route('warehouse.stock-flow.rows',['limit'=>2000]))->assertUnprocessable();

        $this->get(route('warehouse.stock-flow.index',['print'=>1]))->assertOk()
            ->assertSee('موجودی در جریان',false)
            ->assertSee('چاپ / ذخیره PDF')
            ->assertSee('صف جمع‌آوری')
            ->assertSee('در حال جمع‌آوری');
        $this->assertSame(2,Product::count());
    }

    public function test_queue_document_details_show_invoice_customer_seller_status_and_quantity_without_shipped(): void
    {
        $this->owner();
        [$product, $variant] = $this->stock();
        $seller = User::factory()->create(['name' => 'فروشنده آزمایشی']);
        $this->invoice($product, $variant, Invoice::STATUS_PENDING_COLLECTION, 60);
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $invoice->forceFill([
            'customer_name' => 'مشتری آزمایشی',
            'seller_id' => $seller->id,
        ])->saveQuietly();
        $this->invoice($product, $variant, Invoice::STATUS_READY_TO_SHIP, 25);
        $this->invoice($product, $variant, Invoice::STATUS_SHIPPED, 15);

        $before = WarehouseStock::query()->where('product_variant_id', $variant->id)->value('quantity');
        $response = $this->getJson(route('warehouse.stock-flow.details', [
            'variant' => $variant->id, 'kind' => 'queue',
        ]))->assertOk()->assertJsonCount(1, 'rows');

        $response->assertJsonPath('rows.0.document_number', $invoice->uuid)
            ->assertJsonPath('rows.0.customer_name', 'مشتری آزمایشی')
            ->assertJsonPath('rows.0.seller_name', 'فروشنده آزمایشی')
            ->assertJsonPath('rows.0.quantity', 60)
            ->assertJsonPath('rows.0.status_label', 'در صف جمع‌آوری')
            ->assertJsonPath('has_more', false);
        $this->assertSame($before, WarehouseStock::query()->where('product_variant_id', $variant->id)->value('quantity'));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_details_group_duplicate_invoice_lines_and_paginate_by_document(): void
    {
        $this->owner();
        [$product, $variant] = $this->stock();
        $this->invoice($product, $variant, Invoice::STATUS_COLLECTING, 4);
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        InvoiceItem::withoutEvents(fn () => InvoiceItem::create([
            'invoice_id'=>$invoice->id,'product_id'=>$product->id,
            'variant_id'=>$variant->id,'quantity'=>6,'price'=>300000,
        ]));
        $this->invoice($product, $variant, Invoice::STATUS_WAREHOUSE_RECEIVED, 3);
        $first = $this->getJson(route('warehouse.stock-flow.details', [
            'variant'=>$variant->id,'kind'=>'queue','limit'=>1,
        ]))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.quantity', 10)
            ->assertJsonPath('has_more', true);
        $second = $this->getJson(route('warehouse.stock-flow.details', [
            'variant'=>$variant->id,'kind'=>'queue','limit'=>1,'after_id'=>$first->json('next_cursor'),
        ]))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.quantity', 3)
            ->assertJsonPath('has_more', false);
    }

    public function test_official_reservation_details_link_to_preinvoice_and_exclude_converted_holds(): void
    {
        $this->owner();
        [$product, $variant] = $this->stock();
        $seller = User::factory()->create(['name'=>'فروشنده پیش‌فاکتور']);
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::create([
            'uuid' => 'PI-'.Str::random(10),
            'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
            'seller_id' => $seller->id, 'created_by' => $seller->id,
            'customer_name' => 'مشتری پیش‌فاکتور',
            'customer_mobile' => '09120000000',
            'total_price' => 1000,
        ]));
        $reservation = PreinvoiceDraftReservation::create([
            'token' => (string) Str::uuid(), 'user_id'=>$seller->id,
            'preinvoice_order_id'=>$order->id, 'reservation_scope'=>'official',
            'product_id'=>$product->id, 'variant_id'=>$variant->id,
            'quantity'=>9,'expires_at'=>now()->addDay(),
        ]);
        $url = route('warehouse.stock-flow.details', [
            'variant'=>$variant->id,'kind'=>'official',
        ]);
        $this->getJson($url)->assertOk()->assertJsonCount(1,'rows')
            ->assertJsonPath('rows.0.quantity',9)
            ->assertJsonPath('rows.0.document_number',$order->uuid)
            ->assertJsonPath('rows.0.seller_name','فروشنده پیش‌فاکتور');
        $this->assertStringContainsString(
            'print=warehouse',
            $this->getJson($url)->assertOk()->json('rows.0.document_url')
        );

        $this->invoice($product,$variant,Invoice::STATUS_PENDING_COLLECTION,9);
        Invoice::latest('id')->firstOrFail()->forceFill([
            'preinvoice_order_id'=>$order->id,
        ])->saveQuietly();
        $this->getJson($url)->assertOk()->assertJsonCount(0,'rows');
        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_details_validate_input_and_protect_access(): void
    {
        [$product,$variant] = $this->stock();
        $url = route('warehouse.stock-flow.details', ['variant'=>$variant->id,'kind'=>'queue']);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active'=>true,'can_access_erp'=>true]))
            ->getJson($url)->assertForbidden();
        $this->owner();
        $this->getJson(route('warehouse.stock-flow.details',[
            'variant'=>$variant->id,'kind'=>'all',
        ]))->assertUnprocessable();
        $this->getJson(route('warehouse.stock-flow.details',[
            'variant'=>$variant->id,'kind'=>'queue','limit'=>1000,
        ]))->assertUnprocessable();
        $this->getJson(route('warehouse.stock-flow.details',[
            'variant'=>9999999,'kind'=>'queue',
        ]))->assertNotFound();
    }

    public function test_missing_central_warehouse_fails_closed_without_creating_one(): void
    {
        $this->owner();
        // The standard test migration already seeds a central warehouse.
        Warehouse::query()->where('type', 'central')->update(['type' => 'detached']);
        $before = Warehouse::count();
        $this->getJson(route('warehouse.stock-flow.rows'))->assertStatus(503);
        $this->assertSame($before, Warehouse::count());
        $this->assertSame(0, Warehouse::query()->where('type', 'central')->count());
    }

    public function test_unauthenticated_or_unauthorized_requests_are_denied(): void
    {
        $this->get(route('warehouse.stock-flow.index'))->assertRedirect();
        $this->getJson(route('warehouse.stock-flow.rows'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active'=>true,'can_access_erp'=>true]));
        $this->getJson(route('warehouse.stock-flow.rows'))->assertForbidden();
    }
}
