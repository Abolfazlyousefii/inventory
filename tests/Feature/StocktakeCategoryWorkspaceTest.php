<?php

namespace Tests\Feature;

use App\Models\{Category, Invoice, InvoiceItem, PreinvoiceDraftReservation, PreinvoiceOrder, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeCategoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $user = User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner','web'));
        $this->actingAs($user);
    }

    private function fixture(): array
    {
        $root = Category::withoutEvents(fn () => Category::create(['name'=>'تجهیزات جانبی '.Str::random(6)]));
        $airpods = Category::withoutEvents(fn () => Category::create(['name'=>'ایرپاد','parent_id'=>$root->id]));
        $nested = Category::withoutEvents(fn () => Category::create(['name'=>'ایرپاد پرو','parent_id'=>$airpods->id]));
        $unrelated = Category::withoutEvents(fn () => Category::create(['name'=>'کابل '.Str::random(6)]));
        $warehouse = Warehouse::query()->where('type','central')->first()
            ?? Warehouse::withoutEvents(fn()=>Warehouse::create(['type'=>'central','name'=>'انبار مرکزی','is_active'=>1]));
        $make = function(Category $category,string $name,int $stock) use ($warehouse): array {
            $product = Product::withoutEvents(fn()=>Product::create([
                'category_id'=>$category->id,'name'=>$name,'sku'=>'STC-'.Str::random(9),
                'stock'=>$stock,'price'=>150000,'is_sellable'=>true,
            ]));
            $variant = ProductVariant::withoutEvents(fn()=>ProductVariant::create([
                'product_id'=>$product->id,'variant_name'=>'مشکی','variant_code'=>'V-'.Str::random(12),
                'stock'=>$stock,'sell_price'=>150000,'is_active'=>true,'sales_enabled'=>true,
            ]));
            WarehouseStock::withoutEvents(fn()=>WarehouseStock::create([
                'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,
                'product_variant_id'=>$variant->id,'quantity'=>$stock,
            ]));
            return [$product,$variant];
        };
        return [$root,$airpods,$nested,$make($airpods,'ایرپاد نسل ۲',8),
            $make($nested,'ایرپاد پرو',4),$make($unrelated,'کابل شارژ',11)];
    }

    public function test_selecting_subcategory_returns_all_nested_products_and_variants_without_writing_stock(): void
    {
        $this->signIn();
        [, $airpods, , [$first, $v1], [$second, $v2], [$unrelated]] = $this->fixture();

        $response = $this->getJson(route('stock-count-documents.category-variants',[
            'category_id'=>$airpods->id,
        ]))->assertOk()->assertJsonCount(2,'rows')->assertJsonPath('has_more',false);
        $this->assertEqualsCanonicalizing([$first->id,$second->id],array_column($response->json('rows'),'product_id'));
        $this->assertEqualsCanonicalizing([$v1->id,$v2->id],array_column($response->json('rows'),'variant_id'));
        $this->assertNotContains($unrelated->id,array_column($response->json('rows'),'product_id'));
        $this->assertSame(0,StockCountDocument::count());
        $this->assertSame(0,StockMovement::count());
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
    }

    public function test_category_pagination_is_cursor_based_and_rejects_invalid_filters(): void
    {
        $this->signIn();
        [$root, $airpods, , [$first,$v1], [$second,$v2]]=$this->fixture();
        $firstPage=$this->getJson(route('stock-count-documents.category-variants',[
            'category_id'=>$root->id,'limit'=>1,
        ]))->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('has_more',true);
        $secondPage=$this->getJson(route('stock-count-documents.category-variants',[
            'category_id'=>$root->id,'limit'=>1,'after_id'=>$firstPage->json('next_cursor'),
        ]))->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('has_more',false);
        $this->assertNotSame($firstPage->json('rows.0.variant_id'),$secondPage->json('rows.0.variant_id'));
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>$airpods->id,'limit'=>201]))
            ->assertUnprocessable();
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>999999]))
            ->assertUnprocessable();
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>$root->id,'after_id'=>-1]))
            ->assertUnprocessable();
    }

    public function test_original_legacy_group_page_remains_accessible_but_new_form_is_inline(): void
    {
        $this->signIn();
        $this->fixture();
        $this->get(route('stock-count-documents.category-workspace'))->assertOk()
            ->assertSee('انبارگردانی دسته‌ای')
            ->assertSee('ذخیره پیش‌نویس')
            ->assertSee('نمایش محصولات دسته')
            ->assertSee('هر محصول سند جداگانه')
            ->assertSee('id="stocktakeCategoryApp"',false);
        $this->get(route('stock-count-documents.create'))->assertOk()
            ->assertSee('نمایش کالاهای دسته در همین صفحه')
            ->assertSee('data-embedded="1"', false)
            ->assertDontSee(route('stock-count-documents.category-workspace'),false);
    }

    public function test_category_view_matches_the_original_single_product_stock_count_numbers(): void
    {
        $this->signIn();
        [, $airpods, , [$product, $variant]] = $this->fixture();

        $inline = $this->getJson(route('stock-count-documents.category-variants', [
            'category_id' => $airpods->id,
        ]))->assertOk();
        $single = $this->getJson(route('stock-count-documents.variants', $product))->assertOk();

        $categoryRow = collect($inline->json('rows'))->firstWhere('variant_id', $variant->id);
        $originalRow = collect($single->json('data'))->firstWhere('variant_id', $variant->id);
        $this->assertNotNull($categoryRow);
        $this->assertNotNull($originalRow);
        foreach (['system_available', 'active_reserved', 'expected_physical', 'sales_enabled'] as $key) {
            $this->assertSame($originalRow[$key], $categoryRow[$key], 'Stock count mismatch: '.$key);
        }
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(8, (int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'));
    }

    public function test_inline_category_matches_existing_form_with_active_reservation_and_collection_queue(): void
    {
        $this->signIn();
        [, $airpods, , [$product, $variant]] = $this->fixture();
        $seller = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::create([
            'uuid' => 'STK-'.Str::random(10), 'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
            'created_by' => $seller->id, 'customer_name' => 'مشتری تست',
            'customer_mobile' => '09120000000', 'total_price' => 10000,
        ]));
        PreinvoiceDraftReservation::create([
            'token' => (string) Str::uuid(), 'user_id' => $seller->id,
            'preinvoice_order_id' => $order->id, 'reservation_scope' => 'official',
            'product_id' => $product->id, 'variant_id' => $variant->id,
            'quantity' => 3, 'expires_at' => now()->addDay(),
        ]);
        $invoice = Invoice::withoutEvents(fn () => Invoice::create([
            'uuid' => 'INV-'.Str::random(12), 'status' => Invoice::STATUS_PENDING_COLLECTION,
            'customer_name' => 'مشتری تست', 'subtotal' => 10000, 'total' => 10000,
        ]));
        InvoiceItem::withoutEvents(fn () => InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id,
            'variant_id' => $variant->id, 'quantity' => 2,
            'price' => 10000, 'line_total' => 20000,
        ]));

        $group = collect($this->getJson(route('stock-count-documents.category-variants', [
            'category_id' => $airpods->id,
        ]))->assertOk()->json('rows'))->firstWhere('variant_id', $variant->id);
        $single = collect($this->getJson(route('stock-count-documents.variants', $product))
            ->assertOk()->json('data'))->firstWhere('variant_id', $variant->id);

        $this->assertSame(8, $group['system_available']);
        $this->assertSame(3, $group['active_reserved']);
        $this->assertSame(11, $group['expected_physical']);
        $this->assertSame(2, $group['queue_total']);
        foreach (['system_available', 'active_reserved', 'expected_physical', 'queue_total'] as $key) {
            $this->assertSame($single[$key], $group[$key], 'Inconsistent counting figure: '.$key);
        }
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(8, (int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'));
    }

    public function test_create_form_has_inline_category_button_and_same_page_counter_not_a_separate_link(): void
    {
        $this->signIn();
        $this->fixture();
        $html = $this->get(route('stock-count-documents.create'))->assertOk()->getContent();

        $this->assertStringContainsString('id="scShow"', $html);
        $this->assertStringContainsString('id="stocktakeCategoryApp"', $html);
        $this->assertStringContainsString('id="scProducts"', $html);
        $this->assertStringContainsString('id="scClose"', $html);
        $this->assertStringContainsString('data-embedded="1"', $html);
        $this->assertStringNotContainsString('href="'.route('stock-count-documents.category-workspace').'"', $html);
        $this->assertStringContainsString('class="stock-count-stepper', $html);
        $this->assertStringContainsString('id="stocktakeForm"', $html);
    }

    public function test_saving_one_category_product_creates_only_its_draft_and_never_applies_stock(): void
    {
        $this->signIn();
        [, $airpods, , [$first, $v1], [$second, $v2]] = $this->fixture();

        $response = $this->postJson(route('stock-count-documents.store'), [
            'product_id' => $first->id,
            'document_date' => '2026-10-29',
            'actual_quantities' => [$v1->id => 7],
            'description' => 'انبارگردانی از نمای دسته‌ای',
        ])->assertCreated()->assertJsonStructure(['document_id','edit_url','message']);

        $doc = StockCountDocument::query()->findOrFail((int) $response->json('document_id'));
        $this->assertSame('draft', $doc->status);
        $this->assertSame($first->id, $doc->product_id);
        $this->assertSame(7, (int) $doc->items()->where('product_variant_id', $v1->id)->value('actual_quantity'));
        $this->assertSame(0, $doc->items()->where('product_variant_id', $v2->id)->count());
        $this->assertSame(1, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(8, (int) WarehouseStock::query()->where('product_variant_id', $v1->id)->value('quantity'));
        $this->assertSame(4, (int) WarehouseStock::query()->where('product_variant_id', $v2->id)->value('quantity'));
    }

    public function test_unauthorized_access_is_rejected(): void
    {
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>1]))->assertUnauthorized();
        $this->get(route('stock-count-documents.category-workspace'))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_active'=>true,'can_access_erp'=>true]));
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>1]))->assertForbidden();
    }
}
