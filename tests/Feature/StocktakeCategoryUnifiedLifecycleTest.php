<?php

namespace Tests\Feature;

use App\Exceptions\StockCountFinalizeValidationException;
use App\Models\{Product, ProductVariant, StockCountDocument, StockCountDocumentHistory, StockCountDocumentItem, StockMovement, User, Warehouse, WarehouseStock};
use App\Services\{ReservationQueryService, StockCountDocumentService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Event, Http, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StocktakeCategoryUnifiedLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Http::fake();

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });

        foreach ([Product::class, ProductVariant::class, Warehouse::class, WarehouseStock::class,
            StockCountDocument::class, StockCountDocumentItem::class, StockCountDocumentHistory::class, StockMovement::class] as $class) {
            $model = new $class;
            Schema::create($model->getTable(), function (Blueprint $table) use ($model) {
                $table->id();
                foreach (array_unique(array_merge($model->getFillable(), ['created_at', 'updated_at'])) as $column) {
                    if ($column !== 'id') {
                        $table->text($column)->nullable();
                    }
                }
            });
        }

        Schema::create('product_bundle_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->unsignedInteger('quantity');
        });
        Schema::create('bundle_component_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id');
            $table->unsignedInteger('remaining');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamp('shipped_at')->nullable();
        });
        Schema::create('site_stock_holds', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->unsignedBigInteger('invoice_id')->nullable();
        });
        Schema::create('site_stock_hold_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hold_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedInteger('quantity');
        });

        DB::table('categories')->insert([
            ['id' => 10, 'name' => 'دسته اصلی', 'parent_id' => null],
            ['id' => 11, 'name' => 'زیردسته', 'parent_id' => 10],
            ['id' => 12, 'name' => 'دسته دیگر', 'parent_id' => null],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'محصول اول', 'category_id' => 11, 'stock' => 15, 'is_bundle' => 0],
            ['id' => 2, 'name' => 'محصول خارج از محدوده', 'category_id' => 12, 'stock' => 20, 'is_bundle' => 0],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 101, 'product_id' => 1, 'variant_name' => 'مشکی', 'variant_code' => 'A-101', 'stock' => 10, 'is_active' => 1, 'sales_enabled' => 1],
            ['id' => 102, 'product_id' => 1, 'variant_name' => 'سفید', 'variant_code' => 'A-102', 'stock' => 5, 'is_active' => 1, 'sales_enabled' => 1],
            ['id' => 201, 'product_id' => 2, 'variant_name' => 'آبی', 'variant_code' => 'B-201', 'stock' => 20, 'is_active' => 1, 'sales_enabled' => 1],
        ]);
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'انبار مرکزی', 'type' => 'central', 'is_active' => 1]);
        DB::table('warehouse_stocks')->insert([
            ['id' => 1, 'warehouse_id' => 1, 'product_id' => 1, 'product_variant_id' => 101, 'quantity' => 10],
            ['id' => 2, 'warehouse_id' => 1, 'product_id' => 1, 'product_variant_id' => 102, 'quantity' => 5],
            ['id' => 3, 'warehouse_id' => 1, 'product_id' => 2, 'product_variant_id' => 201, 'quantity' => 20],
        ]);

        $this->mock(ReservationQueryService::class)
            ->shouldReceive('quantitiesByVariant')
            ->andReturn(collect([101 => 0, 102 => 0, 201 => 0]));
    }

    private function saveCategory(array $actuals, array $extra = []): StockCountDocument
    {
        return app(StockCountDocumentService::class)->saveCategoryDraft(array_merge([
            'category_id' => 10,
            'document_date' => '2026-11-05',
            'actual_quantities' => $actuals,
            'removed_variant_ids' => [],
        ], $extra), 1);
    }

    public function test_category_draft_contains_only_explicitly_counted_variants_and_keeps_explicit_zero(): void
    {
        $document = $this->saveCategory([101 => 8, 102 => 0]);

        $this->assertSame('category', $document->type);
        $this->assertSame(10, (int) $document->category_id);
        $this->assertNull($document->product_id);
        $this->assertSame([101, 102], $document->items()->orderBy('product_variant_id')->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, (int) $document->counted_count);
        $this->assertSame(1, (int) $document->zeroed_count);
        $this->assertSame(8, (int) $document->total_actual);
        $this->assertSame(2, (int) $document->variants_count);
    }

    public function test_category_save_route_creates_one_document_for_explicit_counts(): void
    {
        $user = new User();
        $user->forceFill(['id' => 1]);
        $this->actingAs($user)->withoutMiddleware();

        $response = $this->postJson('/stock-count-documents/category-document', [
            'category_id' => 10,
            'actual_quantities' => ['101' => 0],
        ]);

        $response->assertOk()
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonPath('document.counted_count', 1)
            ->assertJsonPath('document.zeroed_count', 1);
        $this->assertSame(1, StockCountDocument::count());
        $this->assertSame([101], StockCountDocumentItem::query()->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_category_finalize_route_applies_only_the_saved_counted_rows(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $user = new User();
        $user->forceFill(['id' => 1]);
        $this->actingAs($user)->withoutMiddleware();
        $service = app(StockCountDocumentService::class);

        $response = $this->postJson(route('stock-count-documents.category-document.finalize', $document), [
            'expected_review_token' => $service->reviewToken($document),
        ]);

        $response->assertOk()->assertJsonPath('document.status', 'finalized');
        $this->assertSame(8, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
    }

    public function test_category_draft_rejects_variants_outside_the_selected_category_tree(): void
    {
        try {
            $this->saveCategory([101 => 8, 201 => 0]);
            $this->fail('A variant from another category must not enter the document.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_quantities', $exception->errors());
        }

        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockCountDocumentItem::count());
    }

    public function test_active_parent_draft_prevents_an_overlapping_child_draft(): void
    {
        $this->saveCategory([101 => 8]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->saveCategory([101 => 7], ['category_id' => 11]);
    }

    public function test_category_draft_update_rejects_a_stale_review_token(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $service = app(StockCountDocumentService::class);
        $token = $service->reviewToken($document);
        $document->update(['description' => 'تغییر خارج از صفحه بررسی']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->saveCategory([101 => 9], [
            'document_id' => $document->id,
            'expected_review_token' => $token,
        ]);
    }

    public function test_new_category_draft_requires_at_least_one_explicit_count(): void
    {
        $this->expectException(ValidationException::class);
        $this->saveCategory([]);
    }

    public function test_editing_a_category_draft_can_remove_a_previously_counted_variant(): void
    {
        $document = $this->saveCategory([101 => 8, 102 => 0], [
            'category_id' => 10,
        ]);
        $token = app(StockCountDocumentService::class)->reviewToken($document);

        $updated = $this->saveCategory([101 => 9], [
            'document_id' => $document->id,
            'expected_review_token' => $token,
            'removed_variant_ids' => [102],
        ]);

        $this->assertSame([101], $updated->items()->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(9, (int) $updated->items()->first()->actual_quantity);
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
    }

    public function test_finalization_changes_only_counted_variants_and_leaves_empty_variants_untouched(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $service = app(StockCountDocumentService::class);

        $finalized = $service->finalize($document, 1, false, [
            'expected_review_token' => $service->reviewToken($document),
        ]);

        $this->assertSame('finalized', $finalized->status);
        $this->assertSame(8, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
        $this->assertSame(20, (int) DB::table('warehouse_stocks')->where('product_variant_id', 201)->value('quantity'));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_explicit_zero_is_applied_but_an_unentered_variant_is_not_zeroed(): void
    {
        $document = $this->saveCategory([101 => 0]);
        $service = app(StockCountDocumentService::class);

        $service->finalize($document, 1, false, [
            'expected_review_token' => $service->reviewToken($document),
        ]);

        $this->assertSame(0, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
        $this->assertSame(20, (int) DB::table('warehouse_stocks')->where('product_variant_id', 201)->value('quantity'));
    }

    public function test_stock_change_on_an_uncounted_variant_does_not_block_category_finalization(): void
    {
        $document = $this->saveCategory([101 => 8]);
        DB::table('warehouse_stocks')->where('product_variant_id', 102)->update(['quantity' => 77]);
        $service = app(StockCountDocumentService::class);

        $service->finalize($document, 1, false, [
            'expected_review_token' => $service->reviewToken($document),
        ]);

        $this->assertSame(8, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(77, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
    }

    public function test_stock_change_on_a_counted_variant_blocks_the_whole_category_document(): void
    {
        $document = $this->saveCategory([101 => 8]);
        DB::table('warehouse_stocks')->where('product_variant_id', 101)->update(['quantity' => 9]);
        $service = app(StockCountDocumentService::class);

        try {
            $service->finalize($document, 1, false, [
                'expected_review_token' => $service->reviewToken($document),
            ]);
            $this->fail('A changed counted variant must block finalization.');
        } catch (StockCountFinalizeValidationException $exception) {
            $this->assertContains('stock_changed', array_column($exception->conflicts(), 'reason_code'));
        }

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(9, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
    }
}
