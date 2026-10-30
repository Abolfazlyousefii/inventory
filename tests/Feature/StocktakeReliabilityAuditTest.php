<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeReliabilityAuditTest extends TestCase
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

    private function draft(array $pair, ?int $actual = 6): StockCountDocument
    {
        return app(\App\Services\StockCountDocumentService::class)->createProductDraft([
            'product_id'=>$pair[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$pair[1]->id=>$actual],
        ], auth()->id());
    }

    private function snapshot(): array
    {
        return collect(['stock_count_documents', 'stock_count_document_items', 'stock_count_document_history',
            'stock_movements', 'warehouse_stocks', 'product_variants', 'products'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_single_create_rejects_foreign_count_keys_instead_of_silently_ignoring_them(): void
    {
        [, $first, $second] = $this->fixture();
        $before = $this->snapshot();
        $this->postJson(route('stock-count-documents.store'), [
            'product_id'=>$first[0]->id, 'actual_quantities'=>[$first[1]->id=>6, $second[1]->id=>99],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_noncanonical_variant_key_cannot_silently_drop_a_count(): void
    {
        [, $first] = $this->fixture();
        $before = $this->snapshot();
        $this->postJson(route('stock-count-documents.store'), [
            'product_id'=>$first[0]->id, 'actual_quantities'=>['0'.$first[1]->id=>6],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_single_update_rejects_foreign_note_keys_and_rolls_back_other_count_updates(): void
    {
        [, $first, $second] = $this->fixture();
        $document = $this->draft($first);
        $before = $this->snapshot();
        $this->putJson(route('stock-count-documents.update', $document), [
            'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>3],
            'notes'=>[$second[1]->id=>'belongs to another product'],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_single_finalize_rejects_non_variant_count_keys_before_applying_valid_rows(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first);
        $before = $this->snapshot();
        $this->patchJson(route('stock-count-documents.finalize', $document), [
            'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>3, 'rogue-key'=>0],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stock_changed_then_restored_after_snapshot_still_blocks_old_count(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first);
        $stock = WarehouseStock::where('product_variant_id', $first[1]->id)->sole();
        $started = $stock->updated_at->copy();
        // Simulate the persisted states of two committed movements, including
        // their timestamps: returning to the same quantity is still a stale count.
        \Illuminate\Support\Facades\DB::table('warehouse_stocks')->where('id', $stock->id)->update([
            'quantity'=>7, 'updated_at'=>$started->copy()->addSeconds(2),
        ]);
        \Illuminate\Support\Facades\DB::table('warehouse_stocks')->where('id', $stock->id)->update([
            'quantity'=>8, 'updated_at'=>$started->copy()->addSeconds(4),
        ]);
        $before = $this->snapshot();
        $this->patchJson(route('stock-count-documents.finalize', $document), [
            'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>6],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_service_create_rejects_negative_counts_without_creating_partial_document(): void
    {
        [, $first] = $this->fixture();
        $before = $this->snapshot();
        try {
            app(\App\Services\StockCountDocumentService::class)->createProductDraft([
                'product_id'=>$first[0]->id, 'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>-1],
            ], auth()->id());
            $this->fail('Negative physical count accepted by service');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_service_update_rejects_fractional_counts_without_truncating_them(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first);
        $before = $this->snapshot();
        try {
            app(\App\Services\StockCountDocumentService::class)->updateProductDraft($document, [
                'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>1.5],
            ], auth()->id());
            $this->fail('Fractional physical count truncated by service');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_service_finalize_rejects_nonnumeric_count_instead_of_turning_it_into_zero(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first);
        $before = $this->snapshot();
        try {
            app(\App\Services\StockCountDocumentService::class)->finalize($document, auth()->id(), true, [
                'actual_quantities'=>[$first[1]->id=>'not a number'],
            ]);
            $this->fail('Nonnumeric physical count became zero');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clearing_saved_count_to_null_keeps_it_uncounted_and_prevents_finalization(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first);
        $this->putJson(route('stock-count-documents.update', $document), [
            'document_date'=>'2026-10-30', 'actual_quantities'=>[$first[1]->id=>null],
        ])->assertRedirect();
        $this->assertNull($document->items()->sole()->actual_quantity);
        $this->assertSame(0, (int) $document->fresh()->counted_count);
        $before = $this->snapshot();
        $this->patchJson(route('stock-count-documents.finalize', $document), ['document_date'=>'2026-10-30'])
            ->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_second_draft_cannot_overwrite_stock_applied_from_first_draft_of_same_product(): void
    {
        [, $first] = $this->fixture();
        $a = $this->draft($first, 4);
        $b = $this->draft($first, 7);
        $this->patchJson(route('stock-count-documents.finalize', $a), ['document_date'=>'2026-10-30'])->assertRedirect();
        $before = $this->snapshot();
        $this->patchJson(route('stock-count-documents.finalize', $b), ['document_date'=>'2026-10-30'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_retried_finalize_after_response_loss_cannot_apply_adjustment_twice(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first, 4);
        $payload = ['document_date'=>'2026-10-30'];
        $this->patchJson(route('stock-count-documents.finalize', $document), $payload)->assertRedirect();
        $before = $this->snapshot();
        $this->patchJson(route('stock-count-documents.finalize', $document), $payload)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_new_variant_added_after_draft_requires_refresh_instead_of_silently_losing_its_count(): void
    {
        [, $first] = $this->fixture();
        $document = $this->draft($first, 6);
        $newVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id'=>$first[0]->id, 'variant_name'=>'New blue', 'variant_code'=>Str::random(12),
            'stock'=>3, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
        ]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id'=>$document->warehouse_id, 'product_id'=>$first[0]->id,
            'product_variant_id'=>$newVariant->id, 'quantity'=>3,
        ]));
        $before = $this->snapshot();
        $response = $this->putJson(route('stock-count-documents.update', $document), [
            'actual_quantities'=>[$first[1]->id=>5, $newVariant->id=>2],
        ])->assertUnprocessable();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $document->items()->count());
        $this->assertSame(6, (int) $document->items()->sole()->actual_quantity);
    }
}
