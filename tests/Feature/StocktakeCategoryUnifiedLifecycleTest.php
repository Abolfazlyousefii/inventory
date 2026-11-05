<?php

namespace Tests\Feature;

use App\Exceptions\StockCountFinalizeValidationException;
use App\Http\Controllers\CategoryStocktakeController;
use App\Models\{Product, ProductVariant, StockCountDocument, StockCountDocumentHistory, StockCountDocumentItem, StockMovement, User, Warehouse, WarehouseStock};
use App\Services\{ReservationQueryService, StockCountDocumentService, StocktakeRequestService};
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
        Schema::create('stocktake_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('operation', 16);
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->longText('response')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation', 'request_id']);
        });
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

    /**
     * Build an isolated eight-variant category like the mobile subcategory used
     * during live read-only QA. No production product IDs or database is used.
     */
    private function seedMobileLikeVariantBrowser(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedInteger('quantity');
        });

        $products = [];
        $variants = [];
        $stocks = [];
        foreach (range(3, 8) as $id) {
            $variantId = 100 + $id;
            $products[] = [
                'id' => $id,
                'name' => $id === 5 ? 'موبایل آزمایشی NOVA' : 'موبایل آزمایشی شماره ' . $id,
                'category_id' => 11,
                'is_bundle' => 0,
            ];
            $variants[] = [
                'id' => $variantId, 'product_id' => $id,
                'variant_name' => 'تنوع اصلی', 'variant_code' => 'M-' . $variantId,
                'stock' => 0, 'is_active' => 1, 'sales_enabled' => 1,
            ];
            $stocks[] = [
                'id' => $id + 1, 'warehouse_id' => 1,
                'product_id' => $id, 'product_variant_id' => $variantId,
                'quantity' => $id,
            ];
        }
        DB::table('products')->insert($products);
        DB::table('product_variants')->insert($variants);
        DB::table('warehouse_stocks')->insert($stocks);
    }

    public function test_mobile_like_subcategory_api_returns_cursor_pages_without_duplicate_variants_or_writes(): void
    {
        $this->seedMobileLikeVariantBrowser();
        $this->withoutMiddleware();

        $beforeStocks = DB::table('warehouse_stocks')->orderBy('id')->get()->toArray();
        $beforeDocuments = StockCountDocument::count();
        $beforeMovements = StockMovement::count();

        $all = [];
        $cursor = 0;
        for ($page = 0; $page < 5; $page++) {
            $response = $this->getJson('/stock-count-documents/category-variants?category_id=11&limit=2&after_id=' . $cursor)
                ->assertOk()
                ->assertJsonStructure(['rows', 'next_cursor', 'has_more']);
            $rows = $response->json('rows');
            $this->assertCount(2, $rows, 'Eight variants should occupy four complete two-item pages.');
            foreach ($rows as $row) {
                $this->assertArrayHasKey('product_name', $row);
                $this->assertArrayHasKey('sku', $row);
                $this->assertArrayHasKey('system_available', $row);
                $this->assertArrayHasKey('expected_physical', $row);
                $this->assertArrayHasKey('actual_physical', $row);
                $this->assertNull($row['actual_physical'], 'Opening the category must never write a count.');
                $all[] = (int) $row['variant_id'];
            }
            $next = (int) $response->json('next_cursor');
            $this->assertGreaterThan($cursor, $next);
            $cursor = $next;

            if (! $response->json('has_more')) {
                break;
            }
        }

        $this->assertSame([101, 102, 103, 104, 105, 106, 107, 108], $all);
        $this->assertSame(count($all), count(array_unique($all)));
        $this->assertSame(108, $cursor);

        // Parent scope includes the descendant, but never the unrelated category.
        $parent = $this->getJson('/stock-count-documents/category-variants?category_id=10&limit=60&after_id=0')
            ->assertOk();
        $this->assertCount(8, $parent->json('rows'));
        $this->assertFalse($parent->json('has_more'));
        $this->assertNotContains(201, array_column($parent->json('rows'), 'variant_id'));

        $this->assertEquals($beforeStocks, DB::table('warehouse_stocks')->orderBy('id')->get()->toArray());
        $this->assertSame($beforeDocuments, StockCountDocument::count());
        $this->assertSame($beforeMovements, StockMovement::count());
    }

    public function test_mobile_like_subcategory_api_live_search_and_paging_validation(): void
    {
        $this->seedMobileLikeVariantBrowser();
        $this->withoutMiddleware();

        $response = $this->getJson('/stock-count-documents/category-variants?category_id=11&limit=60&after_id=0&q=NOVA')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', 105)
            ->assertJsonPath('rows.0.product_name', 'موبایل آزمایشی NOVA');
        $this->assertCount(1, $response->json('rows'));
        $this->assertSame(105, (int) $response->json('rows.0.variant_id'));

        $empty = $this->getJson('/stock-count-documents/category-variants?category_id=11&limit=60&after_id=108')
            ->assertOk();
        $this->assertSame([], $empty->json('rows'));
        $this->assertFalse($empty->json('has_more'));
        $this->assertSame(108, $empty->json('next_cursor'));

        $this->getJson('/stock-count-documents/category-variants?category_id=11&limit=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->getJson('/stock-count-documents/category-variants?category_id=11&after_id=-1')
            ->assertUnprocessable()->assertJsonValidationErrors(['after_id']);

        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
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
        $service = app(StockCountDocumentService::class);
        $request = \Illuminate\Http\Request::create(
            route('stock-count-documents.category-document.finalize', $document),
            'POST',
            ['expected_review_token' => $service->reviewToken($document)],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
        $request->setUserResolver(fn () => $user);

        $response = app(\App\Http\Controllers\CategoryStocktakeController::class)->finalize($request, $document);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('finalized', $response->getData(true)['document']['status']);
        $this->assertSame(8, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
    }

    public function test_unified_save_request_status_recovers_the_saved_category_document(): void
    {
        $user = new User();
        $user->forceFill(['id' => 1]);
        $requestId = '2c9e4f16-8c0d-4c4b-9e2d-7d89a3b5c104';
        $request = \Illuminate\Http\Request::create('/stock-count-documents/category-document', 'POST', [
            'request_id' => $requestId,
            'category_id' => 10,
            'actual_quantities' => ['101' => 0],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user);

        $response = app(CategoryStocktakeController::class)->store($request);
        $this->assertSame(200, $response->getStatusCode());

        $statusRequest = \Illuminate\Http\Request::create('/stock-count-documents/requests/'.$requestId, 'GET', [
            'operation' => 'unified_save',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $statusRequest->setUserResolver(fn () => $user);
        $status = app(StocktakeRequestService::class)->status($statusRequest, $requestId);

        $this->assertSame(200, $status->getStatusCode());
        $this->assertSame(
            $response->getData(true)['document_id'],
            $status->getData(true)['document_id'],
        );
        $this->assertSame(1, StockCountDocument::count());
    }

    public function test_unified_finalize_request_status_recovers_without_reapplying_stock(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $user = new User();
        $user->forceFill(['id' => 1]);
        $requestId = 'a2d8d39a-b7a4-4dd7-b48f-42c0a0d12f90';
        $service = app(StockCountDocumentService::class);
        $request = \Illuminate\Http\Request::create(
            route('stock-count-documents.category-document.finalize', $document),
            'POST',
            [
                'request_id' => $requestId,
                'expected_review_token' => $service->reviewToken($document),
            ],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
        $request->setUserResolver(fn () => $user);

        $response = app(\App\Http\Controllers\CategoryStocktakeController::class)->finalize($request, $document);
        $this->assertSame(200, $response->getStatusCode());

        $statusRequest = \Illuminate\Http\Request::create('/stock-count-documents/requests/'.$requestId, 'GET', [
            'operation' => 'unified_finalize',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $statusRequest->setUserResolver(fn () => $user);
        $status = app(StocktakeRequestService::class)->status($statusRequest, $requestId);

        $this->assertSame('finalized', $status->getData(true)['document']['status']);
        $this->assertSame(8, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(1, StockMovement::count());
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

    public function test_category_draft_update_can_add_a_new_counted_variant_to_the_same_document(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $service = app(StockCountDocumentService::class);

        $updated = $this->saveCategory([101 => 8, 102 => 4], [
            'document_id' => $document->id,
            'expected_review_token' => $service->reviewToken($document),
        ]);

        $this->assertSame(1, StockCountDocument::count());
        $this->assertSame(2, (int) $updated->variants_count);
        $this->assertSame([101, 102], $updated->items()->orderBy('product_variant_id')
            ->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(5, (int) $updated->items()->where('product_variant_id', 102)->value('system_available_at_start'));
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

    public function test_category_finalization_rejects_a_stale_review_token_without_changing_stock(): void
    {
        $document = $this->saveCategory([101 => 8]);
        $service = app(StockCountDocumentService::class);
        $token = $service->reviewToken($document);
        $document->update(['description' => 'تغییر خارج از صفحه بررسی']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            $service->finalize($document, 1, false, ['expected_review_token' => $token]);
        } finally {
            $this->assertSame(10, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_changed_reservation_on_a_counted_variant_blocks_category_finalization(): void
    {
        $reservations = \Mockery::mock(ReservationQueryService::class);
        $reservations->shouldReceive('quantitiesByVariant')->twice()
            ->andReturn(collect([101 => 2]), collect([101 => 3]));
        $this->app->instance(ReservationQueryService::class, $reservations);

        $document = $this->saveCategory([101 => 12]);
        $service = app(StockCountDocumentService::class);

        try {
            $service->finalize($document, 1, false, [
                'expected_review_token' => $service->reviewToken($document),
            ]);
            $this->fail('A changed reservation must block category finalization.');
        } catch (StockCountFinalizeValidationException $exception) {
            $this->assertContains('reservation_changed', array_column($exception->conflicts(), 'reason_code'));
        }

        $this->assertSame(10, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_one_conflict_prevents_every_counted_variant_from_being_applied(): void
    {
        $document = $this->saveCategory([101 => 8, 102 => 4]);
        DB::table('warehouse_stocks')->where('product_variant_id', 102)->update(['quantity' => 6]);
        $service = app(StockCountDocumentService::class);

        try {
            $service->finalize($document, 1, false, [
                'expected_review_token' => $service->reviewToken($document),
            ]);
            $this->fail('One stale counted row must roll back the whole category document.');
        } catch (StockCountFinalizeValidationException $exception) {
            $this->assertContains('stock_changed', array_column($exception->conflicts(), 'reason_code'));
        }

        $this->assertSame(10, (int) DB::table('warehouse_stocks')->where('product_variant_id', 101)->value('quantity'));
        $this->assertSame(6, (int) DB::table('warehouse_stocks')->where('product_variant_id', 102)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('draft', $document->fresh()->status);
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
