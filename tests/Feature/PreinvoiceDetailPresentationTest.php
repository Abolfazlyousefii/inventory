<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, PreinvoiceOrder, PreinvoiceOrderItem, PreinvoiceOrderReview, Product, User, WarehouseReviewItemLog};
use App\Services\{PreinvoiceCustomerPdfService, PreinvoiceTimelineService};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PreinvoiceDetailPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dir=storage_path('app/preinvoice-detail-implementation');if(!is_dir($dir))mkdir($dir,0775,true);
        \Illuminate\Support\Facades\Schema::create('categories', function (\Illuminate\Database\Schema\Blueprint $t) {$t->id();$t->string('name');});
        \Illuminate\Support\Facades\Schema::create('permissions', function (\Illuminate\Database\Schema\Blueprint $t) {$t->id();$t->string('key')->nullable();$t->string('name')->nullable();$t->string('guard_name')->nullable();});
    }
    private function order(): PreinvoiceOrder
    {
        $order = new PreinvoiceOrder(['uuid'=>'01553','status'=>'draft','customer_name'=>'مشتری نمونه', 'customer_mobile'=>'09120000000',
            'description'=>'<script>alert(1)</script>', 'discount_amount'=>1000, 'shipping_price'=>500]);
        $order->setRelation('invoice', null)->setRelation('creator', null)->setRelation('warehouseReviewer', null)->setRelation('shippingMethod', null);
        $item = new PreinvoiceOrderItem(['quantity'=>2,'price'=>10000,'line_discount_amount'=>500]);
        $item->setRelation('product', new Product(['name'=>'کالای نمونه']))->setRelation('variant', null);
        $order->setRelation('items',new Collection([$item]));
        $log = new ActivityLog(['action'=>'updated','description'=>'ویرایش فروشنده', 'occurred_at'=>'2026-10-07 09:00:00', 'properties'=>['old'=>['status'=>'draft'], 'changes'=>['quantity'=>2,'note'=>'<img src=x onerror=alert(1)>']]]);
        $log->setRelation('user',null);
        $review = new PreinvoiceOrderReview(['action'=>'finance_edited','reason'=>'اصلاح مالی', 'before_items'=>[['quantity'=>1]], 'after_items'=>[['quantity'=>2]]]);
        $review->created_at='2026-10-07 10:00:00';$review->setRelation('user',null);
        $warehouse = new WarehouseReviewItemLog(['action'=>'quantity_changed','old_quantity'=>3,'new_quantity'=>2,'approved_quantity'=>2,'product_name_snapshot'=>'کالای نمونه']);
        $warehouse->created_at='2026-10-07 11:00:00';$warehouse->setRelation('user',null);
        $order->setRelation('activityLogs',new Collection([$log]))->setRelation('reviews',new Collection([$review]))->setRelation('warehouseReviewItemLogs',new Collection([$warehouse]));
        return $order;
    }

    public function test_timeline_keeps_all_three_sources_in_time_order_and_reads_without_writes(): void
    {
        DB::enableQueryLog();
        $entries=app(PreinvoiceTimelineService::class)->entries($this->order());
        $this->assertCount(3,$entries);
        $this->assertSame('بررسی اقلام انبار',$entries[0]['source']);
        $this->assertContains('تعداد از 3 به 2',$entries[0]['details']);
        $this->assertStringContainsString('اقلام قبل از تغییر',implode(' ', $entries[1]['details']));
        $this->assertContains('مقادیر قبلی / وضعیت: پیش‌نویس',$entries[2]['details']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_actual_layout_escapes_log_and_customer_text_and_uses_customer_actions(): void
    {
        $this->withoutVite();
        $user=Mockery::mock(User::class)->makePartial();$user->forceFill(['id'=>1,'name'=>'نمونه']);
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('hasAnyRole')->andReturn(false);
        $user->shouldReceive('getRoleNames')->andReturn(collect());
        $user->shouldReceive('getPermissionsViaRoles')->andReturn(collect());
        $this->actingAs($user);
        $order=$this->order();$timeline=app(PreinvoiceTimelineService::class)->entries($order);
        $html=view('archive.preinvoice-show',compact('order','timeline'))->render();
        $this->assertStringContainsString('?download=pdf',$html);
        $this->assertStringContainsString('?mode=customer',$html);
        $this->assertStringContainsString('<dialog',$html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>',$html);
        $this->assertStringContainsString('&lt;script&gt;',$html);
        $dir=storage_path('app/preinvoice-detail-implementation');if(!is_dir($dir))mkdir($dir,0775,true);
        file_put_contents($dir.'/rendered.html',$html);
    }

    public function test_pdf_entry_uses_the_exact_existing_customer_print_and_opens_print_after_assets_load(): void
    {
        $data=['mode'=>'customer','title'=>'پیش‌فاکتور فروش','number'=>'01553','numberLabel'=>'شماره پیش‌فاکتور', 'customerNumber'=>null,
            'registeredAt'=>'2026-10-07','issuedAt'=>null,'status'=>'پیش‌نویس','seller'=>'فروشنده نمونه',
            'customer'=>['name'=>'مشتری نمونه','mobile'=>'09120000000','address'=>'آدرس نمونه','code'=>'1'],
            'shipping'=>['method'=>'ارسال','cost'=>500,'description'=>null], 'company'=>config('company'), 'logo'=>'', 'backUrl'=>'/',
            'items'=>collect([['description'=>'کالای نمونه | مشکی','inventoryCode'=>'123','warehouseMap'=>'بدون نقشه','quantity'=>2,'unitPrice'=>10000,'lineDiscount'=>500,'netUnitPrice'=>9750,'lineTotal'=>19500]]),
            'totals'=>['itemsNetSubtotal'=>19500,'invoiceDiscount'=>500,'shipping'=>500,'total'=>19500,'paid'=>null,'remaining'=>null]];
        $original=view('prints.invoice', ['printData'=>$data])->render();
        $html=view('prints.preinvoice-customer-browser-pdf', ['printData'=>$data])->render();
        $withoutScript=preg_replace('/<script>.*?<\/script>/s', '', $html);
        $this->assertSame(trim($original), trim($withoutScript));
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('document.fonts.ready', $html);
        $this->assertStringContainsString('image.decode()', $html);
        $this->assertStringContainsString('customer-mode', $html);
        $this->assertStringNotContainsString('کنترل انبار', $html);
        file_put_contents(storage_path('app/preinvoice-detail-implementation/customer-existing.html'), $original);
        file_put_contents(storage_path('app/preinvoice-detail-implementation/customer-browser-pdf.html'), $html);
    }

    public function test_pdf_request_cannot_read_another_creators_preinvoice(): void
    {
        \Illuminate\Support\Facades\Schema::create('preinvoice_orders', function(\Illuminate\Database\Schema\Blueprint $t){$t->id();$t->string('uuid');$t->integer('created_by');});
        DB::table('preinvoice_orders')->insert(['uuid'=>'01553','created_by'=>2]);
        $this->actingAs((new User())->forceFill(['id'=>1]));
        $request=\Illuminate\Http\Request::create('/preinvoice/my/01553?download=pdf');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(\App\Http\Controllers\PreinvoiceController::class)->myShow('01553',$request,app(\App\Services\SalesPrintDocumentService::class));
    }

    public function test_timeline_includes_deleted_item_audit_but_excludes_other_orders(): void
    {
        \Illuminate\Support\Facades\Schema::create('activity_logs', function(\Illuminate\Database\Schema\Blueprint $t){$t->id();$t->string('subject_type');$t->integer('subject_id');$t->integer('user_id')->nullable();$t->string('action');$t->string('description');$t->json('properties');$t->timestamp('occurred_at')->nullable();$t->timestamps();});
        foreach([7,8]as$id) DB::table('activity_logs')->insert(['subject_type'=>PreinvoiceOrderItem::class,'subject_id'=>100+$id,'action'=>'deleted','description'=>'حذف ردیف '.$id,'properties'=>json_encode(['attributes'=>['preinvoice_order_id'=>$id,'quantity'=>3]]),'occurred_at'=>'2026-10-07 12:00:00']);
        $order=$this->order();$order->id=7;$order->exists=true;
        $entries=app(PreinvoiceTimelineService::class)->entries($order);
        $this->assertCount(4,$entries);
        $this->assertSame('حذف ردیف 7',$entries[0]['title']);
        $this->assertNotContains('حذف ردیف 8',$entries->pluck('title')->all());
    }
}
