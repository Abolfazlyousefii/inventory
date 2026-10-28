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


    public function test_stocktake_registration_shows_queue_breakdown_per_variant_without_changing_saved_stock(): void
    {
        $this->owner();
        [$product,$variant] = $this->stock('قاب شمارش',200);
        $this->invoice($product,$variant,Invoice::STATUS_PENDING_COLLECTION,100);
        $this->invoice($product,$variant,Invoice::STATUS_WAREHOUSE_RECEIVED,7);
        $this->invoice($product,$variant,Invoice::STATUS_COLLECTING,3);
        $this->invoice($product,$variant,Invoice::STATUS_READY_TO_SHIP,33);
        $this->invoice($product,$variant,Invoice::STATUS_SHIPPED,44);
        $before = WarehouseStock::query()->where('product_variant_id',$variant->id)->value('quantity');
        $moves = DB::table('stock_movements')->count();

        $this->get(route('stock-count-documents.create'))->assertOk()
            ->assertSee('موجودی در صف جمع‌آوری')
            ->assertSee('اطلاع‌رسانی فقط‌خواندنی');

        $response = $this->getJson(route('stock-count-documents.variants', ['product'=>$product->id,'limit'=>100]))
            ->assertOk()
            ->assertJsonPath('data.0.variant_id',$variant->id)
            ->assertJsonPath('data.0.system_available',200)
            ->assertJsonPath('data.0.pending_collection',100)
            ->assertJsonPath('data.0.warehouse_received',7)
            ->assertJsonPath('data.0.collecting',3)
            ->assertJsonPath('data.0.queue_total',110);

        // Existing stocktaking arithmetic must remain untouched:
        // flow is displayed separately, never silently added to expected_physical.
        $this->assertSame(200,$response->json('data.0.expected_physical'));
        $this->assertSame($before,WarehouseStock::query()->where('product_variant_id',$variant->id)->value('quantity'));
        $this->assertSame($moves,DB::table('stock_movements')->count());
    }

    public function test_existing_draft_shows_current_collection_queue_but_keeps_saved_stock_snapshot(): void
    {
        $this->owner();
        [$product,$variant] = $this->stock('کالای شمارش موجود',200);

        $document = app(\App\Services\StockCountDocumentService::class)->createProductDraft([
            'product_id' => $product->id,
            'document_date' => now()->toDateString(),
            'actual_quantities' => [$variant->id => 200],
        ], (int) auth()->id());
        $this->invoice($product,$variant,Invoice::STATUS_PENDING_COLLECTION,17);

        $this->get(route('stock-count-documents.edit', $document))->assertOk()
            ->assertSee('موجودی در صف جمع‌آوری');
        $this->getJson(route('stock-count-documents.variants', [
            'product'=>$product->id,'document_id'=>$document->id,
        ]))->assertOk()
            ->assertJsonPath('data.0.queue_total',17)
            ->assertJsonPath('data.0.expected_physical',200)
            ->assertJsonPath('data.0.actual_physical',200);

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(200,(int)$document->items()->first()->actual_quantity);
    }

    public function test_stocktake_inline_queue_is_zero_for_an_unrelated_variant_and_does_not_expose_other_products(): void
    {
        $this->owner();
        [$first,$v1] = $this->stock('محصول یکم',10);
        [$second,$v2] = $this->stock('محصول دوم',10);
        $this->invoice($second,$v2,Invoice::STATUS_PENDING_COLLECTION,15);

        $this->getJson(route('stock-count-documents.variants',['product'=>$first->id]))
            ->assertOk()
            ->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.variant_id',$v1->id)
            ->assertJsonPath('data.0.queue_total',0);

        $this->getJson(route('stock-count-documents.variants',['product'=>$second->id]))
            ->assertOk()
            ->assertJsonPath('data.0.queue_total',15);
    }

    public function test_unauthenticated_or_unauthorized_requests_are_denied(): void
    {
        $this->get(route('warehouse.stock-flow.index'))->assertRedirect();
        $this->getJson(route('warehouse.stock-flow.rows'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active'=>true,'can_access_erp'=>true]));
        $this->getJson(route('warehouse.stock-flow.rows'))->assertForbidden();
    }
}
