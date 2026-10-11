<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use App\Services\PaymentReportService;
use App\Http\Controllers\PaymentReportController;
use Illuminate\Http\Request;

class PaymentReportTest extends TestCase
{
    protected function setUp():void
    {
        parent::setUp();$this->travelTo(now()->setDate(2026,10,10)->startOfDay());
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT',fn(...$values)=>implode('',$values));
        Schema::create('invoice_payments',function(Blueprint $t){$t->id();$t->integer('invoice_id')->nullable();$t->integer('customer_id')->nullable();$t->integer('created_by')->nullable();$t->string('method');$t->bigInteger('amount');$t->string('paid_at')->nullable();$t->string('bank_name')->nullable();$t->string('payment_identifier')->nullable();});
        Schema::create('cheques',function(Blueprint $t){$t->id();$t->integer('invoice_payment_id');$t->string('received_at')->nullable();$t->string('due_date')->nullable();$t->string('bank_name')->nullable();$t->string('cheque_number')->nullable();$t->string('customer_name')->nullable();$t->string('status')->nullable();});
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->integer('customer_id')->nullable();$t->string('uuid');$t->string('customer_name');$t->string('customer_mobile')->nullable();});
        Schema::create('customers',function(Blueprint $t){$t->id();$t->string('name')->nullable();$t->string('first_name')->nullable();$t->string('last_name')->nullable();$t->string('mobile')->nullable();});
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('customer_ledgers',function(Blueprint $t){$t->id();$t->integer('amount');});
        DB::table('customers')->insert(['id'=>1,'first_name'=>'نورا','last_name'=>'تفاهمی','mobile'=>'09058059525']);
        DB::table('invoice_payments')->insert([
            ['id'=>1,'customer_id'=>1,'method'=>'cash','amount'=>100,'paid_at'=>'2026-10-01 00:00:00'],
            ['id'=>2,'customer_id'=>1,'method'=>'cheque','amount'=>300,'paid_at'=>'2026-10-03'],
            ['id'=>3,'customer_id'=>1,'method'=>'cash','amount'=>200,'paid_at'=>'2026-10-10 23:59:59'],
            ['id'=>4,'customer_id'=>1,'method'=>'cash','amount'=>999,'paid_at'=>'2026-10-11'],
            ['id'=>5,'customer_id'=>1,'method'=>'cheque','amount'=>500,'paid_at'=>null],
        ]);
        DB::table('cheques')->insert([
            ['id'=>1,'invoice_payment_id'=>2,'received_at'=>'2026-10-01','due_date'=>'2026-10-09','bank_name'=>'ملی'],
            ['id'=>2,'invoice_payment_id'=>2,'received_at'=>'2026-10-01','due_date'=>'2026-10-12','bank_name'=>'ملی'],
            ['id'=>3,'invoice_payment_id'=>5,'received_at'=>null,'due_date'=>null,'bank_name'=>'ملی'],
        ]);
        DB::table('customer_ledgers')->insert(['amount'=>9999999]);
    }
    private function filters():array{return ['from'=>'2026-10-01','to'=>'2026-10-10','method'=>null,'customer'=>null,'bank'=>null];}
    public function test_payment_is_counted_once_and_date_boundaries_include_date_only_cheques():void
    {
        DB::enableQueryLog();$r=app(PaymentReportService::class)->report($this->filters());
        $this->assertSame(['count'=>2,'amount'=>300],$r['totals']['cash']);$this->assertSame(['count'=>1,'amount'=>300],$r['totals']['cheque']);
        $this->assertSame(1,$r['undated']);$this->assertSame(1,$r['duplicate_cheque_payments']);$this->assertCount(2,$r['trend']);
        $this->assertSame([],array_values(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query']))));
    }
    public function test_due_groups_are_mutually_exclusive_and_independent_of_received_range():void
    {
        $r=app(PaymentReportService::class)->report(array_merge($this->filters(),['from'=>'2026-10-10']));$due=collect($r['due'])->keyBy('key');
        $this->assertSame(0,$due['past']['count']);$this->assertSame(300,$due['week']['amount']);$this->assertSame(500,$due['undated']['amount']);$this->assertSame(2,array_sum(array_column($r['due'],'count')));
    }
    public function test_filters_apply_to_totals_and_detail_and_customer_name_uses_current_person():void
    {
        $f=array_merge($this->filters(),['bank'=>'ملی','customer'=>'نورا تفاهمی']);$s=app(PaymentReportService::class);$r=$s->report($f);$rows=$s->rows($s->received($f))->get();
        $this->assertSame(0,$r['totals']['cash']['count']);$this->assertCount(1,$rows);$this->assertSame('نورا تفاهمی',$rows[0]->customer_name);$this->assertSame(300,(int)$rows[0]->amount);
    }
    public function test_invalid_dates_and_unknown_due_bucket_are_rejected():void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PaymentReportController::class)->filters(Request::create('/','GET',['date_from'=>'1405/07/10','date_to'=>'1405/07/01','bucket'=>'wrong']));
    }
    public function test_online_receipts_are_included_and_cash_with_cheque_metadata_is_flagged_without_reclassifying_money():void
    {
        DB::table('invoice_payments')->insert(['id'=>6,'customer_id'=>1,'method'=>'online','amount'=>50,'paid_at'=>'2026-10-10']);
        DB::table('cheques')->insert(['invoice_payment_id'=>1,'received_at'=>'2026-09-01','due_date'=>'2026-10-10','bank_name'=>'ملی']);
        $r=app(PaymentReportService::class)->report($this->filters());
        $this->assertSame(['count'=>3,'amount'=>350],$r['totals']['cash']);$this->assertSame(300,$r['totals']['cheque']['amount']);
        $this->assertSame(1,$r['method_mismatches']);$this->assertSame(100,collect($r['due'])->firstWhere('key','today')['amount']);
        $this->assertSame('cash',DB::table('invoice_payments')->where('id',1)->value('method'));
    }
    public function test_export_strings_are_not_excel_formulas_and_leading_zero_identifiers_survive():void
    {
        $sheet=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$cell=$sheet->getActiveSheet()->getCell('A1');$export=new \App\Exports\PaymentReportExport(collect());
        $export->bindValue($cell,'=1+1');$this->assertSame('s',$cell->getDataType());$export->bindValue($cell,'001234');$this->assertSame('001234',$cell->getValue());
    }
    private function actor(bool $allowed)
    {
        $u=\Mockery::mock(\App\Models\User::class)->makePartial();$u->forceFill(['id'=>7,'name'=>'کاربر گزارش','is_active'=>true,'can_access_erp'=>true]);
        $u->shouldReceive('isSuperAdmin')->andReturn(false);$u->shouldReceive('hasAnyRole')->andReturn(false);$u->shouldReceive('hasPermission')->andReturn(false);$u->shouldReceive('getRoleNames')->andReturn(collect());$u->shouldReceive('getPermissionsViaRoles')->andReturn(collect($allowed?[(object)['key'=>'page.finance.accounts']]:[]));return $u;
    }
    public function test_all_endpoints_require_report_access_and_detail_is_paginated():void
    {
        $this->actingAs($this->actor(false));foreach(['finance.reports.receipts','finance.reports.receipts.detail','finance.reports.receipts.export'] as $name)$this->getJson(route($name))->assertForbidden();
        $this->actingAs($this->actor(true));
        $this->getJson(route('finance.reports.receipts.detail',['date_from'=>'1405/07/09','date_to'=>'1405/07/18']))->assertOk()->assertJsonPath('total',3)->assertJsonCount(3,'rows')->assertJsonPath('rows.0.url',null);
        $this->getJson(route('finance.reports.receipts.detail',['scope'=>'due','bucket'=>'week']))->assertOk()->assertJsonPath('total',1);
        $this->getJson(route('finance.reports.receipts.detail',['date_from'=>'1405/12/31']))->assertUnprocessable();
    }
    public function test_page_and_print_render_and_escape_customer_names():void
    {
        Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('key')->nullable();$t->string('name')->nullable();$t->string('guard_name')->nullable();});
        DB::table('customers')->update(['name'=>'<script>alert(1)</script>']);$this->actingAs($this->actor(true));$this->withoutVite();
        $r=$this->get(route('finance.reports.receipts',['date_from'=>'1405/07/09','date_to'=>'1405/07/18']));$r->assertOk()->assertSee('id="prFilter"',false)->assertSee('id="prDue"',false)->assertSee('id="prData"',false);
        file_put_contents(storage_path('app/payment-report-20261010/rendered.html'),$r->getContent());
        $this->get(route('finance.reports.receipts.export',['format'=>'print','date_from'=>'1405/07/09','date_to'=>'1405/07/18']))->assertOk()->assertSee('&lt;script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
        $this->get(route('finance.reports.receipts.export',['format'=>'xlsx']))->assertOk();
    }
}
