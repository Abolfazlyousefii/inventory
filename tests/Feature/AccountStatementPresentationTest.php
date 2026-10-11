<?php
namespace Tests\Feature;
use App\Models\{Customer, CustomerLedger, Invoice};
use App\Services\AccountStatementPresentationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class AccountStatementPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('invoices', function(Blueprint $t){$t->id();$t->string('status');});
        Schema::create('customer_ledgers', function(Blueprint $t){$t->id();$t->integer('customer_id');$t->string('type');$t->bigInteger('amount');$t->string('reference_type')->nullable();$t->integer('reference_id')->nullable();$t->timestamps();});
    }
    private function balances(array $ids, int $net): array
    {
        $customer=new Customer();$customer->id=1;
        $rows=CustomerLedger::whereIn('id',$ids)->orderByDesc('created_at')->orderByDesc('id')->get();
        $effective=CustomerLedger::effectiveForBalance()->whereIn('id',$ids)->pluck('id')->mapWithKeys(fn($id)=>[$id=>true]);
        return app(AccountStatementPresentationService::class)->pageBalances($customer,$rows,$effective,$net);
    }
    public function test_page_balances_include_opening_credit_and_ignore_other_customers(): void
    {
        DB::table('customer_ledgers')->insert([
            ['id'=>1,'customer_id'=>1,'type'=>'debit','amount'=>100,'created_at'=>'2026-10-01 10:00:00'],
            ['id'=>2,'customer_id'=>1,'type'=>'credit','amount'=>180,'created_at'=>'2026-10-02 10:00:00'],
            ['id'=>3,'customer_id'=>1,'type'=>'debit','amount'=>20,'created_at'=>'2026-10-03 10:00:00'],
            ['id'=>4,'customer_id'=>2,'type'=>'debit','amount'=>99999,'created_at'=>'2026-10-04 10:00:00'],
        ]);
        $this->assertSame([3=>-110,2=>-130,1=>50],$this->balances([1,2,3],-110));
        $this->assertSame([2=>-130,1=>50],$this->balances([1,2],-110));
    }
    public function test_same_timestamp_page_boundary_and_cancelled_document_are_handled(): void
    {
        DB::table('invoices')->insert(['id'=>1,'status'=>Invoice::STATUS_NOT_SHIPPED]);
        foreach([1,2,3,4] as $id)DB::table('customer_ledgers')->insert(['id'=>$id,'customer_id'=>1,'type'=>$id===2?'credit':'debit','amount'=>10,'created_at'=>'2026-10-01 10:00:00','reference_type'=>$id===3?Invoice::class:null,'reference_id'=>$id===3?1:null]);
        $this->assertSame([4=>10,3=>0,2=>0,1=>10],$this->balances([1,2,3,4],10));
        $this->assertSame([3=>0,2=>0,1=>10],$this->balances([1,2,3],10));
    }
    public function test_undated_entries_and_empty_page_do_not_write_any_records(): void
    {
        DB::table('customer_ledgers')->insert([
            ['id'=>1,'customer_id'=>1,'type'=>'debit','amount'=>30,'created_at'=>null],
            ['id'=>2,'customer_id'=>1,'type'=>'credit','amount'=>10,'created_at'=>null],
            ['id'=>3,'customer_id'=>1,'type'=>'debit','amount'=>20,'created_at'=>'2026-10-01 10:00:00'],
        ]);
        DB::enableQueryLog();
        $this->assertSame([2=>20,1=>30],$this->balances([1,2],40));
        $this->assertSame([],$this->balances([],40));
        $writes=array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete|alter|create|drop)/i',$q['query']));
        $this->assertSame([],$writes);
    }
}
