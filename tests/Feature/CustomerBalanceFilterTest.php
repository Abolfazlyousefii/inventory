<?php
namespace Tests\Feature;
use App\Http\Controllers\CustomerController;
use App\Models\Invoice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class CustomerBalanceFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('customers',function(Blueprint $t){$t->id();foreach(['first_name','last_name','name','mobile','company_name','city','address','extra_description','reservation_tier'] as $f)$t->string($f)->nullable();$t->integer('opening_balance')->nullable();$t->boolean('is_active')->default(true);$t->softDeletes();$t->timestamps();});
        Schema::create('customer_ledgers',function(Blueprint $t){$t->id();$t->integer('customer_id');$t->string('type');$t->integer('amount');$t->string('reference_type')->nullable();$t->integer('reference_id')->nullable();});
        Schema::create('customer_phones',function(Blueprint $t){$t->id();$t->integer('customer_id');$t->string('phone');$t->boolean('is_primary')->default(false);});
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->string('status');});
        foreach([1=>50,2=>0,3=>10,4=>0,5=>null] as $id=>$opening)DB::table('customers')->insert(['id'=>$id,'first_name'=>'نام '.$id,'opening_balance'=>$opening]);
        DB::table('invoices')->insert(['id'=>1,'status'=>Invoice::cancelledStatuses()[0]]);
        DB::table('customer_ledgers')->insert([
            ['customer_id'=>1,'type'=>'debit','amount'=>70,'reference_type'=>null,'reference_id'=>null],
            ['customer_id'=>1,'type'=>'credit','amount'=>20,'reference_type'=>null,'reference_id'=>null],
            ['customer_id'=>2,'type'=>'credit','amount'=>30,'reference_type'=>null,'reference_id'=>null],
            ['customer_id'=>3,'type'=>'credit','amount'=>10,'reference_type'=>null,'reference_id'=>null],
            ['customer_id'=>4,'type'=>'debit','amount'=>999,'reference_type'=>Invoice::class,'reference_id'=>1],
        ]);
    }
    private function list(array $filters=[])
    {
        return app(CustomerController::class)->index(Request::create('/customers','GET',$filters))->getData()['customers'];
    }
    public function test_balance_filters_match_effective_balances_including_nulls_and_cancelled_invoice(): void
    {
        $this->assertSame([1],$this->list(['balance_status'=>'debt'])->pluck('id')->all());
        $this->assertSame(100,$this->list(['balance_status'=>'debt'])->first()->balance);
        $this->assertSame([2],$this->list(['balance_status'=>'credit'])->pluck('id')->all());
        $this->assertSame(-30,$this->list(['balance_status'=>'credit'])->first()->balance);
        $this->assertSame([5,4,3],$this->list(['balance_status'=>'settled'])->pluck('id')->all());
        $this->assertSame(5,$this->list()->total());
    }
    public function test_filter_keeps_search_active_status_pagination_and_excludes_deleted_customers(): void
    {
        for($id=6;$id<=30;$id++)DB::table('customers')->insert(['id'=>$id,'first_name'=>'دیگر','opening_balance'=>100]);
        DB::table('customers')->where('id',30)->update(['deleted_at'=>now()]);
        DB::table('customers')->where('id',29)->update(['is_active'=>false]);
        $page=$this->list(['balance_status'=>'debt','status'=>'active']);
        $this->assertSame(24,$page->total());$this->assertSame(20,$page->count());$this->assertSame(28,$page->first()->id);
        $this->assertSame([1],$this->list(['balance_status'=>'debt','q'=>'نام'])->pluck('id')->all());
    }
}
