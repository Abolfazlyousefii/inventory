<?php
namespace Tests\Feature;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SalesPrintDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
class WarehouseMapRetirementTest extends TestCase
{
    public function test_retired_routes_and_command_are_not_registered(): void
    {
        foreach(Route::getRoutes() as $route){$this->assertFalse(str_starts_with($route->uri(),'warehouse-map'));}
        $commands=\Illuminate\Support\Facades\Artisan::all();
        $this->assertArrayNotHasKey('warehouse-map:migrate-product-locations',$commands);
    }
    public function test_print_item_formatting_needs_no_warehouse_location_or_stock_queries(): void
    {
        $item=new InvoiceItem(['quantity'=>2,'price'=>10000,'line_discount_amount'=>500]);
        $variant=new ProductVariant(['variant_name'=>'مشکی','variant_code'=>'123']);$variant->setRelation('modelList',null);$variant->setRelation('color',null);
        $item->setRelation('product',new Product(['name'=>'کالای نمونه']));$item->setRelation('variant',$variant);
        DB::enableQueryLog();DB::flushQueryLog();
        $rows=(new \ReflectionMethod(SalesPrintDocumentService::class,'items'))->invoke(app(SalesPrintDocumentService::class),collect([$item]));
        $this->assertEmpty(DB::getQueryLog());$this->assertArrayNotHasKey('warehouseMap',$rows[0]);
        $this->assertSame(2,$rows[0]['quantity']);$this->assertSame(19500,$rows[0]['lineTotal']);$this->assertSame('123',$rows[0]['inventoryCode']);
    }
    public function test_both_print_modes_render_without_map_and_keep_quantities_and_customer_totals(): void
    {
        \Illuminate\Support\Facades\Schema::create('categories',function(\Illuminate\Database\Schema\Blueprint $t){$t->id();$t->string('name');});
        $data=['mode'=>'warehouse','title'=>'فاکتور فروش','number'=>'01884','numberLabel'=>'شماره فاکتور','customerNumber'=>null,'registeredAt'=>'2026-10-07','issuedAt'=>null,'status'=>'ارسال شده','seller'=>'فروشنده نمونه','customer'=>['name'=>'مشتری نمونه','mobile'=>'09120000000','address'=>'آدرس نمونه','code'=>'1'],'shipping'=>['method'=>'ارسال','cost'=>500,'description'=>null],'company'=>config('company'),'logo'=>'','backUrl'=>'/','items'=>collect([['description'=>'کالای نمونه | مشکی','inventoryCode'=>'123','quantity'=>2,'unitPrice'=>10000,'lineDiscount'=>500,'netUnitPrice'=>9750,'lineTotal'=>19500]]),'totals'=>['itemsNetSubtotal'=>19500,'invoiceDiscount'=>500,'shipping'=>500,'total'=>19500,'paid'=>null,'remaining'=>null]];
        foreach(['warehouse','customer'] as $mode){$data['mode']=$mode;$html=view('prints.invoice',['printData'=>$data])->render();$this->assertStringNotContainsString('نقشه انبار',$html);$this->assertStringContainsString('کالای نمونه',$html);$this->assertStringContainsString('123',$html);$this->assertSame($mode==='warehouse',str_contains($html,'کنترل انبار'));file_put_contents(storage_path('app/purchase-returns-20261011/retired-map-'.$mode.'.html'),$html);}
    }
}
