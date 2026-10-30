<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeAutomaticDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tehran has crossed midnight while UTC is still October 30.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-30 21:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        // Match the nullable production draft schema; recovered SQLite legacy
        // migrations still declare the original non-null count column.
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) =>
                $table->integer('actual_quantity')->nullable()->change()
        );
        $user = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $root = Category::withoutEvents(fn () => Category::create(['name'=>'Bulk '.Str::random(8)]));
        $child = Category::withoutEvents(fn () => Category::create(['name'=>'Child '.Str::random(8), 'parent_id'=>$root->id]));
        $outside = Category::withoutEvents(fn () => Category::create(['name'=>'Outside '.Str::random(8)]));
        $warehouse = Warehouse::where('type', 'central')->first()
            ?? Warehouse::withoutEvents(fn () => Warehouse::create(['name'=>'انبار مرکزی', 'type'=>'central', 'is_active'=>true]));
        $make = function (Category $category) use ($warehouse): array {
            $product = Product::withoutEvents(fn () => Product::create([
                'name'=>'Bulk product', 'category_id'=>$category->id, 'sku'=>Str::random(12),
                'stock'=>8, 'price'=>150000, 'is_sellable'=>false,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id'=>$product->id, 'variant_name'=>'Black', 'variant_code'=>Str::random(12),
                'stock'=>8, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id'=>$warehouse->id, 'product_id'=>$product->id,
                'product_variant_id'=>$variant->id, 'quantity'=>8,
            ]));
            return [$product, $variant];
        };
        return [$root, $make($root), $make($child), $make($outside)];
    }

    private function assertNoInventoryChanges(): void
    {
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::where('quantity', '<>', 8)->count());
    }

    private function historicalDraft(array $pair): StockCountDocument
    {
        return app(\App\Services\StockCountDocumentService::class)->createProductDraft([
            'product_id'=>$pair[0]->id, 'document_date'=>'2026-09-17',
            'actual_quantities'=>[$pair[1]->id=>6],
        ], auth()->id());
    }

    public function test_single_create_without_client_date_uses_current_tehran_date_across_utc_midnight(): void
    {
        [, $first] = $this->fixture();
        $response = $this->postJson(route('stock-count-documents.store'), [
            'product_id'=>$first[0]->id, 'actual_quantities'=>[$first[1]->id=>2],
        ])->assertCreated();
        $document = StockCountDocument::findOrFail($response->json('document_id'));
        $this->assertSame('2026-10-31', $document->document_date->toDateString());
        $this->assertNoInventoryChanges();
    }

    public function test_single_create_ignores_stale_future_and_malformed_client_dates(): void
    {
        [, $first] = $this->fixture();
        foreach (['2025-01-01', '2099-12-31', 'client-controlled-invalid-date'] as $date) {
            $response = $this->postJson(route('stock-count-documents.store'), [
                'product_id'=>$first[0]->id, 'document_date'=>$date,
                'actual_quantities'=>[$first[1]->id=>2],
            ])->assertCreated();
            $this->assertSame('2026-10-31', StockCountDocument::findOrFail($response->json('document_id'))->document_date->toDateString());
        }
        $this->assertNoInventoryChanges();
    }

    public function test_bulk_create_without_client_date_uses_current_tehran_date_for_every_product(): void
    {
        [$root, $first, $second] = $this->fixture();
        $response = $this->postJson('/stock-count-documents/category-drafts', [
            'category_id'=>$root->id,
            'products'=>array_map(fn ($pair) => ['product_id'=>$pair[0]->id,
                'actual_quantities'=>[$pair[1]->id=>2]], [$first, $second]),
        ])->assertSuccessful()->assertJsonCount(2, 'documents');
        foreach ($response->json('documents') as $row) {
            $this->assertSame('2026-10-31', StockCountDocument::findOrFail($row['document_id'])->document_date->toDateString());
        }
        $this->assertNoInventoryChanges();
    }

    public function test_bulk_create_ignores_stale_future_malformed_and_nested_product_dates(): void
    {
        [$root, $first] = $this->fixture();
        foreach (['2025-01-01', '2099-12-31', 'client-controlled-invalid-date'] as $date) {
            $response = $this->postJson('/stock-count-documents/category-drafts', [
                'category_id'=>$root->id, 'document_date'=>$date,
                'products'=>[['product_id'=>$first[0]->id, 'document_date'=>'2050-01-01',
                    'actual_quantities'=>[$first[1]->id=>2]]],
            ])->assertSuccessful();
            $this->assertSame('2026-10-31', StockCountDocument::findOrFail($response->json('documents.0.document_id'))->document_date->toDateString());
        }
        $this->assertNoInventoryChanges();
    }

    public function test_bulk_resave_uses_current_date_for_existing_category_draft(): void
    {
        [$root, $first] = $this->fixture();
        $document = $this->historicalDraft($first);
        $this->postJson('/stock-count-documents/category-drafts', [
            'category_id'=>$root->id, 'document_date'=>'2099-12-31',
            'products'=>[['product_id'=>$first[0]->id, 'document_id'=>$document->id,
                'actual_quantities'=>[$first[1]->id=>2]]],
        ])->assertSuccessful();
        $this->assertSame('2026-10-31', $document->fresh()->document_date->toDateString());
        $this->assertSame(1, StockCountDocument::count());
        $this->assertNoInventoryChanges();
    }

    public function test_single_draft_edit_update_preserves_historical_date_ignoring_client_date(): void
    {
        [, $first] = $this->fixture();
        $document = $this->historicalDraft($first);
        $this->get(route('stock-count-documents.edit', $document))->assertOk();
        $this->put(route('stock-count-documents.update', $document), [
            'document_date'=>'2099-12-31', 'actual_quantities'=>[$first[1]->id=>2],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-09-17', $document->fresh()->document_date->toDateString());
        $this->assertSame(2, (int) $document->items()->sole()->actual_quantity);
        $this->assertNoInventoryChanges();
    }

    public function test_single_draft_finalize_preserves_historical_date(): void
    {
        [, $first] = $this->fixture();
        $document = $this->historicalDraft($first);
        $this->patch(route('stock-count-documents.finalize', $document), [
            'document_date'=>'2099-12-31', 'actual_quantities'=>[$first[1]->id=>2],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('finalized', $document->fresh()->status);
        $this->assertSame('2026-09-17', $document->fresh()->document_date->toDateString());
        $this->assertSame(2, (int) WarehouseStock::where('product_variant_id', $first[1]->id)->value('quantity'));
    }
}

