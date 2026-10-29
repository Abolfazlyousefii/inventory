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

    public function test_live_search_matches_product_or_variant_within_selected_category_and_keeps_counts_read_only(): void
    {
        $this->signIn();
        [, $airpods, , [$first,$v1], [$second,$v2], [$unrelated]] = $this->fixture();

        $response = $this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$airpods->id,'q'=>'پرو',
        ]))->assertOk()->assertJsonCount(1,'rows')
            ->assertJsonPath('rows.0.variant_id',$v2->id);
        $byVariant=$this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$airpods->id,'q'=>$v1->variant_code,
        ]))->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('rows.0.variant_id',$v1->id);
        $this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$airpods->id,'q'=>str_repeat('X',81),
        ]))->assertUnprocessable();
        $this->assertSame(0,StockCountDocument::count());
        $this->assertSame(0,StockMovement::count());
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
        $this->assertSame(4,(int)WarehouseStock::where('product_variant_id',$v2->id)->value('quantity'));
    }

    public function test_stocktake_form_explains_explicit_zero_and_embeds_live_search_in_same_page(): void
    {
        $this->signIn();
        $this->fixture();
        $html=$this->get(route('stock-count-documents.create'))->assertOk()->getContent();
        $this->assertStringContainsString('id="scLiveSearch"',$html);
        $this->assertStringContainsString('عدد ۰ را صریحاً', $html);
        $this->assertStringNotContainsString('name="confirm_empty_as_zero"',$html);
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
        $this->assertSame(2, $group['pending_collection']);
        $this->assertSame(0, $group['warehouse_received']);
        $this->assertSame(0, $group['collecting']);
        foreach (['system_available', 'active_reserved', 'expected_physical', 'pending_collection', 'warehouse_received', 'collecting', 'queue_total'] as $key) {
            $this->assertSame($single[$key], $group[$key], 'Inconsistent counting figure: '.$key);
        }
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(8, (int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'));
    }

    public function test_stocktake_form_header_and_form_are_siblings_at_full_page_width(): void
    {
        $this->signIn();
        $this->fixture();
        $response = $this->get(route('stock-count-documents.create'))->assertOk();
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prior = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML('<?xml encoding="UTF-8"?>'.$response->getContent()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($dom);

        // The missing closing div in the title toolbar previously trapped
        // the *entire form* in a horizontal flex row (squeezed to one side).
        $this->assertSame(1, $xpath->query('//*[@id="stocktakeApp"]/form[@id="stocktakeForm"]')->length,
            'The stocktake form must be a direct block child of its page, not inside the flex heading.');
        $this->assertSame(1, $xpath->query(
            '//*[@id="stocktakeApp"]/*[contains(concat(" ", normalize-space(@class), " "), " stocktake-page__header ")]'
        )->length, 'The heading must be a separate full-width block.');
        $this->assertSame(0, $xpath->query(
            '//*[@id="stocktakeApp"]/*[contains(concat(" ", normalize-space(@class), " "), " stocktake-page__header ")]//form'
        )->length, 'The title toolbar cannot contain the stocktake form.');
        $this->assertSame(1, $xpath->query(
            '//*[@id="stocktakeForm"]/*[@id="stocktakeCategoryApp"]'
        )->length, 'Category counting remains embedded in the existing form.');
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
        // Existing per-product edit workflow stays unchanged; inline category
        // controls never appear when editing a saved draft.
        $this->get(route('stock-count-documents.edit', $doc))->assertOk()
            ->assertDontSee('id="scShow"', false)
            ->assertSee('id="stocktakeForm"', false);

    }

    public function test_empty_draft_is_valid_but_http_finalization_returns_conflicts_and_preserves_stock(): void
    {
        $this->signIn();
        [, $airpods, , [$product, $variant]]=$this->fixture();
        // The original SQLite legacy migration predates the nullable
        // actual_quantity schema used for product-level draft counting.
        // Recreate the nullable shape applied by the production upgrade.
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) =>
                $table->integer('actual_quantity')->nullable()->change()
        );
        $draftResponse=$this->postJson(route('stock-count-documents.store'), [
            'product_id'=>$product->id,
            'document_date'=>'2026-10-29',
            'actual_quantities'=>[],
        ])->assertCreated();
        $draft=StockCountDocument::findOrFail($draftResponse->json('document_id'));
        $this->assertSame('draft',$draft->status);
        $this->assertNull($draft->items()->where('product_variant_id',$variant->id)->value('actual_quantity'));

        $this->patchJson(route('stock-count-documents.finalize',$draft), [
            'product_id'=>$product->id,'document_date'=>'2026-10-29',
            'confirm_empty_as_zero'=>1, // An old client must not bypass the new safety rule.
        ])->assertUnprocessable()
            ->assertJsonPath('conflicts.0.reason_code','uncounted_variant');
        $this->assertSame('draft',$draft->fresh()->status);
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$variant->id)->value('quantity'));
        $this->assertSame(0,StockMovement::count());
    }

    public function test_category_bulk_draft_creates_one_document_without_adjusting_central_stock(): void
    {
        $this->signIn();
        [, $airpods, , [$p1,$v1], [$p2,$v2], [$other,$v3]] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change()
        );

        $res = $this->postJson(route('stock-count-documents.category-bulk.store'), [
            'category_id' => $airpods->id, 'document_date' => '2026-10-29',
            'request_key' => 'c9e84516-6066-470b-9126-f0cdf5a1dd91',
            'actual_quantities'=>[$v1->id=>7],
        ])->assertCreated()->assertJsonStructure(['document_id','edit_url','message']);
        $doc = StockCountDocument::findOrFail($res->json('document_id'));
        $this->assertSame('category', $doc->type);
        $this->assertSame((int)$airpods->id, (int)$doc->category_id);
        $this->assertNull($doc->product_id);
        $this->assertSame('draft', $doc->status);
        $this->assertSame(2, $doc->items()->count());
        $this->assertSame(7, $doc->items()->where('product_variant_id',$v1->id)->value('actual_quantity'));
        $this->assertNull($doc->items()->where('product_variant_id',$v2->id)->value('actual_quantity'));
        $this->assertSame(0, $doc->items()->where('product_variant_id',$v3->id)->count());
        $this->assertSame(8, (int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
        $this->assertSame(4, (int)WarehouseStock::where('product_variant_id',$v2->id)->value('quantity'));
        $this->assertSame(0, StockMovement::count());

        // Retry the same request, never create a duplicate stocktake document.
        $this->postJson(route('stock-count-documents.category-bulk.store'), [
            'category_id' => $airpods->id, 'document_date' => '2026-10-29',
            'request_key' => 'c9e84516-6066-470b-9126-f0cdf5a1dd91',
            'actual_quantities'=>[$v1->id=>7],
        ])->assertOk()->assertJsonPath('document_id',$doc->id);
        $this->assertSame(1, StockCountDocument::count());
    }

    public function test_category_bulk_finalize_is_atomic_and_rejects_unresolved_variant(): void
    {
        $this->signIn();
        [, $airpods, , [$p1,$v1], [$p2,$v2]] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change()
        );
        $data = ['category_id'=>$airpods->id,'document_date'=>'2026-10-29',
            'request_key'=>'e1a685c9-790e-4ef8-9725-5fad73ac5700',
            'actual_quantities'=>[$v1->id=>7]];
        $docId = $this->postJson(route('stock-count-documents.category-bulk.store'),$data)
            ->assertCreated()->json('document_id');
        $route = route('stock-count-documents.category-bulk.finalize', $docId);
        $this->patchJson($route, ['actual_quantities'=>[$v1->id=>7]])->assertUnprocessable()
            ->assertJsonValidationErrors('review_token');
        $this->assertSame('draft',StockCountDocument::findOrFail($docId)->status);
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
        $this->assertSame(4,(int)WarehouseStock::where('product_variant_id',$v2->id)->value('quantity'));
        $this->assertSame(0, StockMovement::count());

        // Stock changes require a separate review of the persisted draft.
        $this->patchJson($route, ['actual_quantities'=>[$v1->id=>7,$v2->id=>0]])
            ->assertUnprocessable();
        $this->putJson(route('stock-count-documents.category-bulk.update',$docId), [
            'actual_quantities'=>[$v2->id=>0],
        ])->assertOk();
        $review=$this->get(route('stock-count-documents.category-bulk.review',$docId))->assertOk()
            ->assertSee('بررسی مغایرت‌های انبارگردانی')
            ->assertSee('ایرپاد نسل ۲');
        $dom=new \DOMDocument();
        @$dom->loadHTML($review->getContent());
        $token=(new \DOMXPath($dom))->query('//input[@name="review_token"]')->item(0)?->getAttribute('value');
        $this->assertNotEmpty($token);
        $this->patchJson($route, ['review_token'=>$token])
            ->assertOk()->assertJsonPath('status','finalized');
        $this->assertSame(7,(int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
        $this->assertSame(0,(int)WarehouseStock::where('product_variant_id',$v2->id)->value('quantity'));
        $this->assertSame(2, StockMovement::count());
        $this->patchJson($route, ['actual_quantities'=>[$v1->id=>7,$v2->id=>0]])->assertUnprocessable();
    }

    public function test_category_bulk_rejects_foreign_variant_and_invalid_unscoped_counts(): void
    {
        $this->signIn();
        [, $airpods, , [$p1,$v1], [$p2,$v2], [$p3,$foreign]] = $this->fixture();
        $before = StockCountDocument::count();
        $this->postJson(route('stock-count-documents.category-bulk.store'),[
            'category_id'=>$airpods->id,'document_date'=>'2026-10-29',
            'request_key'=>'ba0a2243-0dbd-4b67-88fe-26209c13a68c',
            'actual_quantities'=>[$foreign->id=>50],
        ])->assertUnprocessable();
        $this->assertSame($before, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_category_bulk_controls_are_one_sticky_bar_not_per_product_buttons(): void
    {
        $this->signIn();
        $this->fixture();
        $html=$this->get(route('stock-count-documents.create'))->assertOk()->getContent();
        $this->assertStringContainsString('id="scBulkActions"', $html);
        $this->assertStringContainsString('id="scSaveAll"', $html);
        $this->assertStringContainsString('id="scFinalizeAll"', $html);
        $this->assertStringContainsString('data-bulk-store-url', $html);
    }

    public function test_category_bulk_draft_can_resume_and_clear_a_previously_saved_count(): void
    {
        $this->signIn();
        [, $category, , [$p1,$v1], [$p2,$v2]] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change()
        );
        $docId=$this->postJson(route('stock-count-documents.category-bulk.store'),[
            'category_id'=>$category->id,'document_date'=>'2026-10-29',
            'request_key'=>'df8654d9-e2f7-4456-ad1d-449b8a593cb5',
            'actual_quantities'=>[$v1->id=>0,$v2->id=>4],
        ])->assertCreated()->json('document_id');
        $this->get(route('stock-count-documents.category-bulk.resume',$docId))->assertOk()
            ->assertSee('id="scBulkActions"',false)->assertSee('data-bulk-document-id="'.$docId.'"',false);
        $this->putJson(route('stock-count-documents.category-bulk.update',$docId),[
            'actual_quantities'=>[$v2->id=>null],
        ])->assertOk()->assertJsonPath('status','draft');
        $doc=StockCountDocument::findOrFail($docId);
        $this->assertSame(0,$doc->items()->where('product_variant_id',$v1->id)->value('actual_quantity'));
        $this->assertNull($doc->items()->where('product_variant_id',$v2->id)->value('actual_quantity'));
        $this->assertSame(0,StockMovement::count());
        $this->get(route('stock-count-documents.category-bulk.review',$docId))
            ->assertOk()->assertSee('شمارش نشده')->assertDontSee('name="review_token"', false);
        $this->patchJson(route('stock-count-documents.category-bulk.finalize',$docId),[])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    }

    public function test_category_review_token_is_invalidated_after_draft_is_edited(): void
    {
        $this->signIn();
        [, $category,,[$p,$v],[$otherProduct,$otherVariant]]=$this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change());
        $docId=$this->postJson(route('stock-count-documents.category-bulk.store'), [
            'category_id'=>$category->id,'document_date'=>'2026-10-29',
            'request_key'=>'21abed66-ebed-4a01-89ba-1e664aacbb98',
            'actual_quantities'=>[$v->id=>8,$otherVariant->id=>4],
        ])->assertCreated()->json('document_id');
        $review=$this->get(route('stock-count-documents.category-bulk.review',$docId))->assertOk();
        $dom=new \DOMDocument();
        @$dom->loadHTML($review->getContent());
        $token=(new \DOMXPath($dom))->query('//input[@name="review_token"]')->item(0)?->getAttribute('value');
        $this->assertNotEmpty($token);
        $this->putJson(route('stock-count-documents.category-bulk.update',$docId), [
            'actual_quantities'=>[$v->id=>5],
        ])->assertOk();
        $this->patchJson(route('stock-count-documents.category-bulk.finalize',$docId),
            ['review_token'=>$token])->assertUnprocessable();
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$v->id)->value('quantity'));
        $this->assertSame(0,StockMovement::count());
    }

    public function test_bulk_category_cannot_use_legacy_product_finalize_endpoint(): void
    {
        $this->signIn();
        [, $category,,[$p,$v]]=$this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change()
        );
        $docId=$this->postJson(route('stock-count-documents.category-bulk.store'),[
            'category_id'=>$category->id,'document_date'=>'2026-10-29',
            'request_key'=>'33a0bbc6-1cc1-4b2c-bc10-cff14379e3a8',
            'actual_quantities'=>[$v->id=>8],
        ])->assertCreated()->json('document_id');
        $this->patchJson(route('stock-count-documents.finalize',$docId),[
            'document_date'=>'2026-10-29',
        ])->assertUnprocessable();
        $this->assertSame('draft',StockCountDocument::findOrFail($docId)->status);
        $this->assertSame(0,StockMovement::count());
    }

    public function test_bulk_category_rolls_back_all_adjustments_when_one_variant_stock_changes(): void
    {
        $this->signIn();
        [, $category,,[$p1,$v1],[$p2,$v2]]=$this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change()
        );
        $id=$this->postJson(route('stock-count-documents.category-bulk.store'),[
            'category_id'=>$category->id,'document_date'=>'2026-10-29',
            'request_key'=>'da877e9a-73ec-4211-8d31-9c3efb4ce291',
            'actual_quantities'=>[$v1->id=>7,$v2->id=>2],
        ])->assertCreated()->json('document_id');
        $review=$this->get(route('stock-count-documents.category-bulk.review',$id))->assertOk();
        $dom=new \DOMDocument();
        @$dom->loadHTML($review->getContent());
        $token=(new \DOMXPath($dom))->query('//input[@name="review_token"]')->item(0)?->getAttribute('value');
        $this->assertNotEmpty($token);
        WarehouseStock::where('product_variant_id',$v2->id)->update(['quantity'=>9]);
        $this->patchJson(route('stock-count-documents.category-bulk.finalize',$id),['review_token'=>$token])
            ->assertUnprocessable()->assertJsonPath('conflicts.0.reason_code','stock_changed');
        $this->assertSame('draft',StockCountDocument::findOrFail($id)->status);
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$v1->id)->value('quantity'));
        $this->assertSame(9,(int)WarehouseStock::where('product_variant_id',$v2->id)->value('quantity'));
        $this->assertSame(0,StockMovement::count());
    }

    public function test_category_finalize_refuses_unresolved_collection_queue_to_prevent_double_counting(): void
    {
        $this->signIn();
        [, $category,,[$product,$variant],[$product2,$variant2]]=$this->fixture();
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) => $table->integer('actual_quantity')->nullable()->change());
        $id=$this->postJson(route('stock-count-documents.category-bulk.store'),[
            'category_id'=>$category->id,'document_date'=>'2026-10-29',
            'request_key'=>'f7e11a0b-2ce8-4c1b-8a6e-4bc2ea149985',
            'actual_quantities'=>[$variant->id=>10,$variant2->id=>4],
        ])->assertCreated()->json('document_id');
        $review=$this->get(route('stock-count-documents.category-bulk.review',$id))->assertOk();
        $dom=new \DOMDocument();
        @$dom->loadHTML($review->getContent());
        $token=(new \DOMXPath($dom))->query('//input[@name="review_token"]')->item(0)?->getAttribute('value');
        $this->assertNotEmpty($token);
        $invoice=Invoice::withoutEvents(fn () => Invoice::create([
            'uuid'=>'CT-'.Str::random(10), 'status'=>Invoice::STATUS_PENDING_COLLECTION,
            'customer_name'=>'مشتری','subtotal'=>1000,'total'=>1000,
        ]));
        InvoiceItem::withoutEvents(fn () => InvoiceItem::create([
            'invoice_id'=>$invoice->id, 'product_id'=>$product->id,
            'variant_id'=>$variant->id, 'quantity'=>2,
            'price'=>1000, 'line_total'=>2000,
        ]));
        $this->get(route('stock-count-documents.category-bulk.review',$id))
            ->assertOk()->assertSee('تنوع در صف جمع‌آوری')
            ->assertSee('نیازمند تطبیق فیزیکی')
            ->assertDontSee('name="review_token"', false);
        $this->patchJson(route('stock-count-documents.category-bulk.finalize',$id),[
            'review_token'=>$token,
        ])->assertUnprocessable()->assertJsonPath('conflicts.0.reason_code','collection_queue_unresolved');
        $this->assertSame(0,StockMovement::count());
        $this->assertSame(8,(int)WarehouseStock::where('product_variant_id',$variant->id)->value('quantity'));
        $this->assertSame('draft',StockCountDocument::findOrFail($id)->status);
    }

    public function test_invalid_product_count_rejected_before_stocktake_created(): void
    {
        $this->signIn();
        [, , , [$product, $variant]] = $this->fixture();
        foreach (['not-a-number', -1, 2147483648] as $invalid) {
            $this->postJson(route('stock-count-documents.store'), [
                'product_id' => $product->id, 'document_date' => '2026-10-29',
                'actual_quantities' => [$variant->id => $invalid],
            ])->assertUnprocessable()->assertJsonValidationErrors("actual_quantities.{$variant->id}");
        }
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(8,(int) WarehouseStock::where('product_variant_id',$variant->id)->value('quantity'));
    }

    public function test_unauthorized_access_is_rejected(): void
    {
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>1]))->assertUnauthorized();
        $this->get(route('stock-count-documents.category-workspace'))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_active'=>true,'can_access_erp'=>true]));
        $this->getJson(route('stock-count-documents.category-variants',['category_id'=>1]))->assertForbidden();
    }
}
