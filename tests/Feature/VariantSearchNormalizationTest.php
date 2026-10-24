<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariantSearchNormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('model_lists', function (Blueprint $t) { $t->id(); $t->string('model_name'); $t->string('code')->nullable(); });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id(); $t->integer('product_id'); $t->integer('model_list_id')->nullable();
            foreach (['variant_name','variety_name','variant_code','variety_code'] as $column) $t->string($column)->nullable();
            foreach (['stock','reserved','sell_price'] as $column) $t->integer($column)->default(0);
            $t->boolean('is_active')->default(true); $t->boolean('sales_enabled')->default(true);
        });
        DB::table('model_lists')->insert(['id'=>1,'model_name'=>'POCO X3 Pro','code'=>'X3P']);
        DB::table('product_variants')->insert([
            ['id'=>1,'product_id'=>1,'model_list_id'=>1,'variant_name'=>'قاب شیائومی يخي','variant_code'=>'03074534001','stock'=>4,'sales_enabled'=>true],
            ['id'=>2,'product_id'=>1,'model_list_id'=>null,'variant_name'=>'آيفون ۱۳ پرو مشکی','variant_code'=>'03074534002','stock'=>0,'sales_enabled'=>false],
            ['id'=>3,'product_id'=>2,'model_list_id'=>1,'variant_name'=>'قاب شیائومی يخي','variant_code'=>'03074534003','stock'=>9,'sales_enabled'=>true],
            ['id'=>4,'product_id'=>1,'model_list_id'=>null,'variant_name'=>'POCO X5 Pro یخی','variant_code'=>'03074534004','stock'=>5,'sales_enabled'=>true],
        ]);
    }

    public function test_search_normalizes_both_stored_names_and_query_without_reordering_or_crossing_products(): void
    {
        $product=new Product(); $product->id=1;
        $service=app(ProductSearchService::class);
        foreach (['يخي x ۳ pro','یخی PRO X٣','poco x3pro','۰۳۰۷۴۵۳۴۰۰۱'] as $term) {
            $page=$service->variants($product, Request::create('/variants','GET',['q'=>$term]));
            $this->assertSame([1],$page->pluck('id')->all(),$term);
        }
        foreach (['آیفون 13','آيفون ١٣','۱۳ پرو مشکی'] as $term) {
            $this->assertSame([2],$service->variants($product,Request::create('/variants','GET',['q'=>$term]))->pluck('id')->all(),$term);
        }
        $this->assertSame([1,2,4],$service->variants($product,Request::create('/variants'))->pluck('id')->all());
        $this->assertCount(0,$service->variants($product,Request::create('/variants','GET',['q'=>'آیفون 13','stock_status'=>'in_stock'])));
        $this->assertCount(0,$service->variants($product,Request::create('/variants','GET',['q'=>'آیفون 13','sellable_status'=>'sellable'])));
        $this->assertCount(0,$service->variants($product,Request::create('/variants','GET',['q'=>'%'])));
        DB::table('product_variants')->where('id',4)->update(['variant_code'=>'0123456789']);
        $this->assertSame([4],$service->variants($product,Request::create('/variants','GET',['q'=>'٠١٢٣٤٥٦٧٨٩']))->pluck('id')->all());
    }
}
