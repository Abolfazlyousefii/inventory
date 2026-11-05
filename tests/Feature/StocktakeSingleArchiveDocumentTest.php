<?php

namespace Tests\Feature;

use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\StocktakeLegacyArchiveController;
use App\Support\PageAccessCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StocktakeSingleArchiveDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('category_id');
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_count_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number');
            $table->string('type')->default('product');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('scope_name')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->date('document_date');
            $table->string('status');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('variants_count')->default(0);
            $table->unsignedInteger('counted_count')->default(0);
            $table->unsignedInteger('zeroed_count')->default(0);
            $table->integer('total_increase')->default(0);
            $table->integer('total_decrease')->default(0);
            $table->timestamps();
        });
        Schema::create('stock_count_document_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot')->nullable();
            $table->integer('expected_physical_at_start')->default(0);
            $table->integer('actual_quantity')->nullable();
            $table->integer('difference_quantity')->default(0);
        });
        Schema::create('stocktake_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('operation');
            $table->text('response')->nullable();
            $table->timestamps();
        });

        DB::table('categories')->insert([
            ['id' => 17, 'name' => 'ساعت هوشمند', 'parent_id' => null],
            ['id' => 28, 'name' => 'پاوربانک', 'parent_id' => null],
        ]);
        DB::table('products')->insert([
            ['id' => 11, 'name' => 'ساعت A', 'category_id' => 17],
            ['id' => 12, 'name' => 'ساعت B', 'category_id' => 17],
            ['id' => 13, 'name' => 'پاوربانک', 'category_id' => 28],
        ]);
        DB::table('warehouses')->insert(['id' => 1, 'name' => 'انبار مرکزی']);
        DB::table('users')->insert(['id' => 1, 'name' => 'انباردار']);
        foreach ([
            ['id' => 101, 'document_number' => 'STC-101', 'type' => 'product', 'warehouse_id' => 1, 'product_id' => 11, 'document_date' => '2026-11-05', 'status' => 'finalized', 'created_by' => 1, 'variants_count' => 1, 'counted_count' => 1, 'total_increase' => 3],
            ['id' => 102, 'document_number' => 'STC-102', 'type' => 'product', 'warehouse_id' => 1, 'product_id' => 12, 'document_date' => '2026-11-05', 'status' => 'finalized', 'created_by' => 1, 'variants_count' => 1, 'counted_count' => 1, 'total_decrease' => 2],
            ['id' => 103, 'document_number' => 'STC-103', 'type' => 'category', 'warehouse_id' => 1, 'category_id' => 28, 'scope_name' => 'پاوربانک', 'document_date' => '2026-11-05', 'status' => 'finalized', 'created_by' => 1, 'variants_count' => 1, 'counted_count' => 1, 'total_increase' => 4],
            ['id' => 104, 'document_number' => 'STC-104', 'type' => 'product', 'warehouse_id' => 1, 'product_id' => 13, 'document_date' => '2026-11-05', 'status' => 'draft', 'created_by' => 1],
        ] as $row) DB::table('stock_count_documents')->insert($row);
        DB::table('stock_count_document_items')->insert([
            ['id' => 1, 'document_id' => 101, 'product_id' => 11, 'product_variant_id' => 501, 'product_name_snapshot' => 'ساعت A', 'variant_name_snapshot' => 'مشکی', 'actual_quantity' => 7, 'expected_physical_at_start' => 4, 'difference_quantity' => 3],
            ['id' => 2, 'document_id' => 102, 'product_id' => 12, 'product_variant_id' => 502, 'product_name_snapshot' => 'ساعت B', 'variant_name_snapshot' => 'سفید', 'actual_quantity' => 2, 'expected_physical_at_start' => 4, 'difference_quantity' => -2],
        ]);
        DB::table('stocktake_requests')->insert([
            'id' => 16, 'user_id' => 1, 'operation' => 'finalize',
            'response' => json_encode(['documents' => [['document_id' => 101], ['document_id' => 102]]]),
            'created_at' => '2026-11-05 09:46:51',
        ]);
    }

    private function archive(array $params = [], bool $json = false)
    {
        $request = Request::create('/stocktake', 'GET', $params);
        if ($json) $request->headers->set('Accept', 'application/json');

        return app(StocktakeController::class)->index($request);
    }

    public function test_main_archive_replaces_legacy_product_rows_with_one_read_only_category_entry(): void
    {
        $beforeDocuments = DB::table('stock_count_documents')->orderBy('id')->get()->toArray();
        $beforeItems = DB::table('stock_count_document_items')->orderBy('id')->get()->toArray();

        $view = $this->archive()->getData();
        $entries = $view['documents'];
        $this->assertSame(3, $entries->total());
        $this->assertSame(2, $view['summary']['finalized']);
        $this->assertSame(7, $view['summary']['increase']);
        $this->assertSame(2, $view['summary']['decrease']);
        $this->assertFalse($entries->contains('document_number', 'STC-101'));
        $this->assertFalse($entries->contains('document_number', 'STC-102'));
        $group = $entries->first(fn ($entry) => ($entry->is_archive_group ?? false) === true);
        $this->assertNotNull($group);
        $this->assertSame(16, $group->batch_id);
        $this->assertSame('ساعت هوشمند', $group->scope_name);
        $this->assertSame(2, $group->items_count);
        $this->assertSame(3, $group->total_increase);
        $this->assertSame(2, $group->total_decrease);

        $this->assertEquals($beforeDocuments, DB::table('stock_count_documents')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeItems, DB::table('stock_count_document_items')->orderBy('id')->get()->toArray());
    }

    public function test_virtual_document_detail_shows_all_saved_counts_and_original_document_links(): void
    {
        $view = app(StocktakeLegacyArchiveController::class)->show(16)->getData();
        $this->assertSame('ساعت هوشمند', $view['group']->scope_name);
        $this->assertSame([101, 102], $view['group']->child_documents->pluck('id')->sort()->values()->all());
        $this->assertSame([501, 502], $view['items']->pluck('product_variant_id')->sort()->values()->all());
        $this->assertSame(2, $view['items']->total());
        $this->assertSame(2, DB::table('stock_count_document_items')->count());
    }

    public function test_invalid_or_ambiguous_batches_remain_individual_archive_documents(): void
    {
        DB::table('products')->where('id', 12)->update(['category_id' => 28]);

        $view = $this->archive()->getData();
        $this->assertSame(4, $view['documents']->total());
        $this->assertFalse($view['documents']->contains(fn ($entry) => ($entry->is_archive_group ?? false) === true));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(StocktakeLegacyArchiveController::class)->show(16);
    }

    public function test_precise_document_search_and_json_consumers_keep_original_documents(): void
    {
        $found = $this->archive(['document_number' => 'STC-101'])->getData()['documents'];
        $this->assertSame(1, $found->total());
        $this->assertSame('STC-101', $found->first()->document_number);

        $json = $this->archive([], true)->getData(true);
        $this->assertSame(4, $json['total']);
        $this->assertCount(4, $json['data']);
    }

    public function test_only_authorized_stocktake_viewers_can_reach_historical_category_detail(): void
    {
        $this->assertSame(
            ['page.warehouse.stocktake'],
            PageAccessCatalog::permissionsForRoute('stock-count-documents.legacy-archive.show')
        );
    }
}
