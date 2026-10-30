<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeConsolidationTest extends TestCase
{
    use RefreshDatabase;

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

    private function source(array $pair, ?int $count, string $note = ''): StockCountDocument
    {
        return app(\App\Services\StockCountDocumentService::class)->createProductDraft([
            'product_id'=>$pair[0]->id, 'document_date'=>now('Asia/Tehran')->toDateString(),
            'actual_quantities'=>[$pair[1]->id=>$count], 'notes'=>[$pair[1]->id=>$note],
        ], auth()->id());
    }

    private function tokens(array $sources): array
    {
        return collect($sources)->mapWithKeys(fn ($source) => [$source->id=>
            app(\App\Services\StockCountDocumentService::class)->reviewToken($source)])->all();
    }

    private function consolidate(Category $category, array $sources, ?array $tokens = null): StockCountDocument
    {
        return app(\App\Services\StockCountDocumentService::class)->consolidateCategoryDrafts(
            array_map(fn ($source) => $source->id, $sources), $category->id, auth()->id(), $tokens ?? $this->tokens($sources)
        );
    }

    private function snapshot(): array
    {
        return collect(['stock_count_documents', 'stock_count_document_items', 'stock_count_document_history',
            'stock_movements', 'warehouse_stocks', 'product_variants', 'products'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function assertRejected(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unsafe consolidation was accepted');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertSame($status, $error->status);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_consolidation_preserves_counts_notes_sources_and_history_with_one_visible_category_entry(): void
    {
        [$root, $first, $second, $untouched] = $this->fixture();
        Product::withoutEvents(fn () => $untouched[0]->update(['category_id'=>$root->id]));
        $a = $this->source($first, 6, 'Counted six on shelf');
        $b = $this->source($second, null, 'No count entered yet');
        $aItems = $a->items()->get()->toJson();
        $bItems = $b->items()->get()->toJson();
        $historyIds = \App\Models\StockCountDocumentHistory::pluck('id')->all();
        $parent = $this->consolidate($root, [$a, $b]);
        $this->assertSame('category', $parent->type);
        $this->assertSame($root->id, (int) $parent->category_id);
        $this->assertSame('draft', $parent->status);
        $this->assertSame(3, StockCountDocument::count());
        foreach ([$a, $b] as $source) {
            $this->assertSame('draft', $source->fresh()->status);
            $this->assertSame($parent->id, (int) $source->fresh()->superseded_by_id);
        }
        $this->assertSame($aItems, $a->items()->get()->toJson());
        $this->assertSame($bItems, $b->items()->get()->toJson());
        $this->assertSame(count($historyIds), \App\Models\StockCountDocumentHistory::whereIn('id', $historyIds)->count());
        $this->assertSame(3, $parent->items()->count());
        $firstItem = $parent->items()->where('product_variant_id', $first[1]->id)->sole();
        $secondItem = $parent->items()->where('product_variant_id', $second[1]->id)->sole();
        $this->assertSame(6, (int) $firstItem->actual_quantity);
        $this->assertSame('Counted six on shelf', $firstItem->description);
        $this->assertSame(0, (int) $secondItem->actual_quantity);
        $this->assertSame('No count entered yet', $secondItem->description);
        $this->assertSame(0, (int) $parent->items()->where('product_variant_id', $untouched[1]->id)->sole()->actual_quantity);
        $this->assertSame(6, (int) $parent->total_actual);
        $refs = $parent->history()->get()->map(fn ($entry) => $entry->new_value['source_document_ids'] ?? null)->filter()->flatten()->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $refs);
        $this->getJson(route('stocktake.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $parent->id);
        $this->get(route('stock-count-documents.edit', $a))->assertRedirect(route('stock-count-documents.edit', $parent));
        $this->get(route('stock-count-documents.view', $b))->assertRedirect(route('stock-count-documents.view', $parent));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::where('quantity', '<>', 8)->count());
    }

    public function test_superseded_originals_cannot_be_updated_finalized_or_cancelled(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($second, 0);
        $this->consolidate($root, [$a, $b]);
        $before = $this->snapshot();
        $service = app(\App\Services\StockCountDocumentService::class);
        $this->assertRejected(fn () => $service->updateProductDraft($a, [
            'document_date'=>now('Asia/Tehran')->toDateString(), 'actual_quantities'=>[$first[1]->id=>99],
        ], auth()->id()), 409);
        $this->assertRejected(fn () => $service->finalize($a, auth()->id(), true), 409);
        $this->assertRejected(fn () => $service->cancel($a, auth()->id(), 'old browser'), 409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_changed_source_stock_snapshot_rejects_consolidation_without_refreshing_history(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($second, 0);
        \Illuminate\Support\Facades\DB::table('warehouse_stocks')->where('product_variant_id', $second[1]->id)->update(['quantity'=>7]);
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b]), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_duplicate_product_sources_reject_consolidation_without_choosing_one_count(): void
    {
        [$root, $first] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($first, 3);
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b]), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_changed_active_reservation_rejects_merge_even_when_available_quantity_matches_source(): void
    {
        [$root, $first, $second] = $this->fixture();
        $held = 0;
        $this->mock(\App\Services\ReservationQueryService::class)->shouldReceive('quantitiesByVariant')
            ->andReturnUsing(function (...$arguments) use (&$held, $first, $second) {
                return collect([$first[1]->id=>0, $second[1]->id=>$held]);
            });
        $a = $this->source($first, 6);
        $b = $this->source($second, 8);
        $held = 1;
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b]), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cross_category_source_is_rejected_atomically(): void
    {
        [$root, $first, , $outside] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($outside, 0);
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b]), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_finalized_source_is_rejected_without_changing_other_sources(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($second, 0);
        app(\App\Services\StockCountDocumentService::class)->finalize($b, auth()->id(), true);
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b]), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_or_stale_expected_source_tokens_reject_without_any_changes(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->source($first, 6);
        $b = $this->source($second, 0);
        $tokens = $this->tokens([$a, $b]);
        app(\App\Services\StockCountDocumentService::class)->updateProductDraft($b, [
            'document_date'=>now('Asia/Tehran')->toDateString(), 'actual_quantities'=>[$second[1]->id=>3],
        ], auth()->id());
        $before = $this->snapshot();
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b], $tokens), 409);
        $this->assertRejected(fn () => $this->consolidate($root, [$a, $b], []), 409);
        $this->assertSame($before, $this->snapshot());
    }
}
