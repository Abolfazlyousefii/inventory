<?php
namespace Tests\Feature;

use App\Http\Controllers\StocktakeController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StocktakeArchiveFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['products','warehouses','users'] as $name) Schema::create($name, function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name'); $t->integer('parent_id')->nullable(); });
        Schema::create('stock_count_documents', function (Blueprint $t) {
            $t->id(); $t->string('document_number'); $t->string('type')->default('product'); $t->date('document_date'); $t->string('status'); $t->string('description')->nullable();
            $t->integer('product_id')->nullable(); $t->integer('category_id')->nullable(); $t->string('scope_name')->nullable(); $t->integer('warehouse_id'); $t->integer('created_by');
            $t->integer('total_increase')->default(0); $t->integer('total_decrease')->default(0); $t->integer('superseded_by_id')->nullable(); $t->timestamps();
        });
        Schema::create('stock_count_document_items', function (Blueprint $t) { $t->id(); $t->integer('document_id'); });
        DB::table('products')->insert([['id'=>1,'name'=>'گارد سیلیکونی'],['id'=>2,'name'=>'Charger']]);
        DB::table('warehouses')->insert([['id'=>1,'name'=>'Central'],['id'=>2,'name'=>'Other']]);
        DB::table('users')->insert(['id'=>1,'name'=>'Seller']);
        for ($i=1; $i<=17; $i++) DB::table('stock_count_documents')->insert(['document_number'=>'STC-'.$i,'document_date'=>'2026-10-15','status'=>'finalized','product_id'=>1,'warehouse_id'=>1,'created_by'=>1,'total_increase'=>2,'total_decrease'=>3,'created_at'=>'2026-10-16']);
        DB::table('stock_count_documents')->insert(['document_number'=>'STC-18','document_date'=>'2026-10-16','status'=>'draft','product_id'=>2,'warehouse_id'=>2,'created_by'=>1,'total_increase'=>5]);
    }

    public function test_superseded_draft_is_excluded_from_archive_but_preserved_in_database(): void
    {
        DB::table('stock_count_documents')->insert([
            'document_number'=>'STC-SUPERSEDED',
            'document_date'=>'2026-10-16',
            'status'=>'draft',
            'product_id'=>2,
            'warehouse_id'=>2,
            'created_by'=>1,
            'total_increase'=>500,
            'superseded_by_id'=>1,
        ]);

        $draftView = app(StocktakeController::class)->index(
            Request::create('/stocktake', 'GET', ['status'=>'draft'])
        )->getData();

        $this->assertFalse($draftView['documents']->contains('document_number', 'STC-SUPERSEDED'));
        $this->assertSame('draft', DB::table('stock_count_documents')
            ->where('document_number', 'STC-SUPERSEDED')->value('status'));
        $this->assertSame(1, (int) DB::table('stock_count_documents')
            ->where('document_number', 'STC-SUPERSEDED')->value('superseded_by_id'));
    }

    public function test_filters_and_summaries_cover_all_matching_documents_before_pagination(): void
    {
        foreach ([
            [[],18,39,51], [['q'=>'سيليكوني'],17,34,51], [['warehouse_id'=>1],17,34,51],
            [['status'=>'draft'],1,5,0], [['document_number'=>'STC-18'],1,5,0],
            [['date_from'=>'۱۴۰۵/۰۷/۲۳','date_to'=>'۱۴۰۵/۰۷/۲۳'],17,34,51],
            [['warehouse_id'=>1,'status'=>'finalized','q'=>'گارد','date_to'=>'1405/07/23'],17,34,51],
            [['q'=>'missing'],0,0,0],
        ] as [$filters,$count,$increase,$decrease]) {
            $view=app(StocktakeController::class)->index(Request::create('/stocktake','GET',$filters))->getData();
            $this->assertSame($count,$view['documents']->total());
            $this->assertSame($count,$view['summary']['total']);
            $this->assertSame($increase,$view['summary']['increase']);
            $this->assertSame($decrease,$view['summary']['decrease']);
            $this->assertCount(min(15,$count),$view['documents']);
        }
    }

    public function test_json_archive_keeps_paginator_shape_and_filters(): void
    {
        $request = Request::create('/stocktake', 'GET', ['status'=>'draft']);
        $request->headers->set('Accept','application/json');
        $data=app(StocktakeController::class)->index($request)->getData(true);
        $this->assertSame(1,$data['total']);
        $this->assertCount(1,$data['data']);
        $this->assertSame('draft',$data['data'][0]['status']);
    }

    public function test_category_archive_uses_saved_scope_name_and_identifies_subcategory(): void
    {
        DB::table('categories')->insert([
            ['id'=>7,'name'=>'لوازم جانبی','parent_id'=>null],
            ['id'=>28,'name'=>'نگهدارنده','parent_id'=>7],
        ]);
        DB::table('stock_count_documents')->insert([
            'document_number'=>'STC-CATEGORY-HEADER-HOLDER',
            'type'=>'category',
            'document_date'=>'2026-10-31',
            'status'=>'finalized',
            'product_id'=>null,
            'category_id'=>28,
            'scope_name'=>'Header Holder',
            'warehouse_id'=>1,
            'created_by'=>1,
            'created_at'=>'2026-10-31 09:35:55',
        ]);

        $view = app(StocktakeController::class)->index(Request::create('/stocktake', 'GET'));
        $document = $view->getData()['documents']->firstWhere('document_number', 'STC-CATEGORY-HEADER-HOLDER');

        $this->assertNotNull($document);
        $this->assertTrue($document->relationLoaded('category'));
        $this->assertSame('Header Holder', $document->archive_subject_name);
        $this->assertSame('انبارگردانی زیردسته‌ای', $document->archive_scope_label);
    }

    public function test_archive_search_matches_category_scope_snapshot(): void
    {
        DB::table('categories')->insert(['id'=>28,'name'=>'نگهدارنده','parent_id'=>7]);
        DB::table('stock_count_documents')->insert([
            'document_number'=>'STC-CATEGORY-SEARCH',
            'type'=>'category',
            'document_date'=>'2026-10-31',
            'status'=>'finalized',
            'product_id'=>null,
            'category_id'=>28,
            'scope_name'=>'Header Holder',
            'warehouse_id'=>1,
            'created_by'=>1,
            'created_at'=>'2026-10-31 09:35:55',
        ]);

        $view = app(StocktakeController::class)->index(Request::create('/stocktake', 'GET', ['q'=>'Header Holder']));

        $this->assertSame(1, $view->getData()['documents']->total());
        $this->assertSame('STC-CATEGORY-SEARCH', $view->getData()['documents']->first()->document_number);
    }

    public function test_reversed_date_range_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StocktakeController::class)->index(Request::create('/stocktake','GET',['date_from'=>'1405/07/24','date_to'=>'1405/07/23']));
    }

    public function test_invalid_jalali_dates_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StocktakeController::class)->index(Request::create('/stocktake','GET',['date_from'=>'1405/13/01']));
    }
}
