<?php
namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, InvoiceNote, ActivityLog, Product, User};
use App\Services\InvoiceTimelineService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Mockery;
use Tests\TestCase;

class InvoiceDetailPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
        Schema::create('permissions',fn(Blueprint $t)=>[$t->id(),$t->string('key')->nullable(),$t->string('name')->nullable(),$t->string('guard_name')->nullable()]);
        Schema::create('activity_logs', function(Blueprint $t){$t->id();$t->string('subject_type');$t->integer('subject_id');$t->integer('user_id')->nullable();$t->string('action');$t->text('description');$t->json('properties');$t->timestamp('occurred_at');$t->timestamps();});
    }

    private function invoice(): Invoice
    {
        $invoice=new Invoice(['uuid'=>'01829','customer_name'=>'مشتری نمونه','total'=>20000,'subtotal'=>20000,'status'=>'shipped']);
        $invoice->id=7;
        $item=new InvoiceItem(['quantity'=>2,'price'=>10000,'line_discount_amount'=>0]);$item->id=10;
        $item->setRelation('product',new Product(['name'=>'کالای نمونه']))->setRelation('variant',null);
        $invoice->setRelation('items',new Collection([$item]))->setRelation('payments',new Collection())->setRelation('notes',new Collection());
        foreach(['customer','seller','shippingMethod','preinvoiceOrder']as$r)$invoice->setRelation($r,null);
        $log=new ActivityLog(['description'=>'ارسال فاکتور <script>alert(1)</script>','occurred_at'=>'2026-10-07 10:00:00','properties'=>['changes'=>['quantity'=>2]],'subject_type'=>Invoice::class]);$log->setRelation('user',null);
        $invoice->setRelation('activityLogs',new Collection([$log]));
        return $invoice;
    }

    public function test_history_includes_deleted_children_and_excludes_other_invoices_without_writes(): void
    {
        foreach([7,8]as$id)DB::table('activity_logs')->insert(['subject_type'=>InvoiceItem::class,'subject_id'=>100+$id,'action'=>'deleted','description'=>'حذف ردیف '.$id,'properties'=>json_encode(['original'=>['invoice_id'=>$id,'quantity'=>3]]),'occurred_at'=>'2026-10-07 12:00:00']);
        DB::table('activity_logs')->insert(['subject_type'=>InvoiceNote::class,'subject_id'=>80,'action'=>'created','description'=>'یادداشت ثبت شد','properties'=>json_encode(['attributes'=>['invoice_id'=>7]]),'occurred_at'=>'2026-10-07 13:00:00']);
        $invoice=$this->invoice();$invoice->exists=true;DB::enableQueryLog();
        $entries=app(InvoiceTimelineService::class)->entries($invoice);
        $this->assertCount(3,$entries);$this->assertSame('یادداشت',$entries[0]['source']);
        $this->assertContains('حذف ردیف 7',$entries->pluck('title')->all());$this->assertNotContains('حذف ردیف 8',$entries->pluck('title')->all());
        $this->assertCount(0,collect(DB::getQueryLog())->filter(fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])));
    }

    public function test_page_keeps_existing_actions_and_escapes_history(): void
    {
        $this->withoutVite();$user=Mockery::mock(User::class)->makePartial();$user->forceFill(['id'=>1,'name'=>'نمونه']);
        foreach(['isSuperAdmin','hasPermission','hasAnyRole']as$m)$user->shouldReceive($m)->andReturn(false);
        $user->shouldReceive('getRoleNames')->andReturn(collect());$user->shouldReceive('getPermissionsViaRoles')->andReturn(collect());$this->actingAs($user);
        $invoice=$this->invoice();$timeline=app(InvoiceTimelineService::class)->entries($invoice);
        $html=view('invoices.show',['invoice'=>$invoice,'timeline'=>$timeline,'paidTotal'=>0,'remainingAmount'=>20000,
            'canEditInvoice'=>true,'canRegisterPayments'=>true,'canHandleFinanceActions'=>true,'canCancelInvoice'=>false,'canPrintInvoice'=>true,'backUrl'=>'/invoices','statusLabels'=>['shipped'=>'ارسال شده']])->render();
        $this->assertStringContainsString('data-pd-history',$html);$this->assertStringContainsString('<dialog',$html);
        $this->assertStringContainsString('چاپ فاکتور',$html);$this->assertStringContainsString('ویرایش فاکتور',$html);$this->assertStringContainsString('showPaymentModal',$html);
        $this->assertStringNotContainsString('<script>alert(1)</script>',$html);$this->assertStringContainsString('&lt;script&gt;',$html);
        $dir=storage_path('app/invoice-detail-implementation');if(!is_dir($dir))mkdir($dir,0775,true);file_put_contents($dir.'/rendered.html',$html);
    }

    public function test_warehouse_revisions_show_previous_and_new_values(): void
    {
        Schema::create('users',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
        Schema::create('invoice_collection_revisions',function(Blueprint $t){$t->id();$t->integer('invoice_id');$t->integer('changed_by');$t->integer('revision_number');$t->text('reason_note')->nullable();$t->timestamps();});
        Schema::create('invoice_collection_revision_items',function(Blueprint $t){$t->id();$t->integer('invoice_collection_revision_id');$t->string('product_name_snapshot');$t->string('variant_name_snapshot')->nullable();$t->string('change_type');foreach(['quantity','price','discount','line_total']as$field){$t->integer('old_'.$field)->nullable();$t->integer('new_'.$field)->nullable();}});
        DB::table('users')->insert(['id'=>1,'name'=>'انبار']);
        DB::table('invoice_collection_revisions')->insert(['id'=>1,'invoice_id'=>7,'changed_by'=>1,'revision_number'=>2,'reason_note'=>'اصلاح تعداد','created_at'=>'2026-10-07 15:00:00']);
        DB::table('invoice_collection_revision_items')->insert(['invoice_collection_revision_id'=>1,'product_name_snapshot'=>'کالا','change_type'=>'multiple_changes','old_quantity'=>3,'new_quantity'=>2,'old_price'=>100,'new_price'=>200]);
        $invoice=$this->invoice();$invoice->exists=true;
        $entries=app(InvoiceTimelineService::class)->entries($invoice);
        $this->assertSame('اصلاح اقلام انبار',$entries[0]['source']);$this->assertSame('انبار',$entries[0]['actor']);
        $this->assertContains('تعداد: 3 ← 2',$entries[0]['details']);$this->assertContains('قیمت واحد: 100 ← 200',$entries[0]['details']);
    }
}
