<?php
namespace Tests\Feature;

use App\Models\{Customer,Invoice,InvoicePayment,CustomerLedger,Cheque,User};
use App\Events\InvoiceChangeAuditRequested;
use App\Http\Middleware\{EnsureActiveUser,RoutePermissionMiddleware,EnsurePageAccess,CheckRoleOrRoutePermission};
use App\Services\PaymentRegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Event};
use Mockery;
use Tests\TestCase;

class UnifiedPaymentEntryTest extends TestCase
{
    private Invoice $invoice;
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([InvoiceChangeAuditRequested::class]);
        $this->withoutMiddleware([EnsureActiveUser::class,RoutePermissionMiddleware::class,EnsurePageAccess::class,CheckRoleOrRoutePermission::class]);
        Schema::create('categories',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
        Schema::create('customers',function(Blueprint $t){$t->id();$t->string('display_name')->nullable();$t->string('mobile')->nullable();$t->integer('crm_customer_id')->nullable();$t->integer('opening_balance')->default(0);$t->softDeletes();$t->timestamps();});
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->string('uuid');$t->integer('customer_id');$t->string('customer_name')->nullable();$t->integer('total');$t->string('status');$t->timestamps();});
        Schema::create('invoice_payments',function(Blueprint $t){$t->id();$t->integer('invoice_id');$t->integer('customer_id');$t->integer('created_by')->nullable();$t->string('method');$t->integer('amount');$t->string('paid_at')->nullable();foreach(['bank_name','payment_identifier','receipt_image','note']as$f)$t->string($f)->nullable();$t->timestamps();});
        Schema::create('cheques',function(Blueprint $t){$t->id();$t->integer('invoice_payment_id');$t->integer('amount');foreach(['bank_name','branch_name','cheque_number','due_date','received_at','customer_name','customer_code','account_number','account_holder','image','status']as$f)$t->string($f)->nullable();$t->timestamps();});
        Schema::create('customer_ledgers',function(Blueprint $t){$t->id();$t->integer('customer_id');$t->string('type');$t->integer('amount');$t->string('reference_type');$t->integer('reference_id');$t->string('note')->nullable();$t->timestamps();});
        Schema::create('activity_logs',function(Blueprint $t){$t->id();$t->integer('user_id')->nullable();$t->string('action');$t->string('subject_type');$t->integer('subject_id');$t->text('description');$t->json('properties');$t->timestamp('occurred_at');$t->timestamps();});
        DB::table('customers')->insert(['id'=>1,'display_name'=>'نمونه']);
        DB::table('invoices')->insert(['id'=>1,'uuid'=>'PAY-TEST','customer_id'=>1,'customer_name'=>'نمونه','total'=>10000000,'status'=>Invoice::STATUS_PENDING_COLLECTION]);
        $this->invoice=Invoice::findOrFail(1);
        $this->financeUser(true);
    }
    private function financeUser(bool $allowed): void
    {
        $user=Mockery::mock(User::class)->makePartial();$user->forceFill(['id'=>1,'name'=>'مالی']);
        $user->shouldReceive('getRoleNames')->andReturn(collect());$user->shouldReceive('getPermissionsViaRoles')->andReturn(collect());
        $user->shouldReceive('isSuperAdmin')->andReturn(false);$user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('hasAnyRole')->andReturn($allowed);$user->shouldReceive('can')->andReturn($allowed);$this->actingAs($user);
    }
    private function submit(bool $statement,array $payload)
    {
        return $this->from('/previous')->post($statement?route('account-statements.payments.store',1):route('invoices.payments.store','PAY-TEST'),$statement?['invoice_id'=>1]+$payload:$payload);
    }
    public function test_both_pages_create_identical_cash_records_and_exactly_one_linked_credit(): void
    {
        foreach([false,true]as$statement){
            $this->submit($statement,['method'=>'cash','amount'=>'۱,۲۳۴,۵۶۷','payment_date'=>'۱۴۰۵/۰۷/۱۵','bank_name'=>'ملی','tracking_number'=>'REF-123','description'=>'واریز مشتری'])->assertRedirect('/previous')->assertSessionHasNoErrors();
            $p=InvoicePayment::latest('id')->first();
            $this->assertSame(1234567,(int)$p->amount);$this->assertSame('2026-10-07',$p->paid_at);
            $this->assertSame('REF-123',$p->payment_identifier);$this->assertSame('واریز مشتری',$p->note);
            $credit=CustomerLedger::where('reference_type',InvoicePayment::class)->where('reference_id',$p->id)->sole();
            $this->assertSame((int)$p->amount,(int)$credit->amount);$this->assertSame('credit',$credit->type);
        }
        $this->assertSame(2,InvoicePayment::count());$this->assertSame(2,CustomerLedger::count());$this->assertSame(0,Cheque::count());
        $this->assertSame(2,DB::table('activity_logs')->where('action','invoice_payment_added')->count());
    }
    public function test_both_pages_store_cheque_dates_status_amount_bank_and_note_consistently(): void
    {
        foreach([false,true]as$statement){
            $this->submit($statement,['method'=>'cheque','amount'=>'200,000','bank_name'=>'ملی','received_date'=>'1405/07/15','due_date'=>'1405/08/15','cheque_number'=>'CHK-'.(int)$statement,'cheque_status'=>'registered','description'=>'توضیح چک'])->assertRedirect()->assertSessionHasNoErrors();
            $p=InvoicePayment::with('cheque')->latest('id')->first();
            $this->assertSame('2026-10-07',$p->paid_at);$this->assertSame('2026-11-06',$p->cheque->due_date->format('Y-m-d'));
            $this->assertSame('registered',$p->cheque->status);$this->assertSame('ملی',$p->bank_name);$this->assertSame('توضیح چک',$p->note);
            $this->assertSame((int)$p->amount,(int)$p->cheque->amount);
        }
        $this->assertSame(2,CustomerLedger::count());
    }
    public function test_invalid_dates_status_missing_cheque_fields_and_nonpositive_amount_write_nothing(): void
    {
        foreach([false,true]as$statement){
            $this->submit($statement,['method'=>'cash','amount'=>'100','payment_date'=>'1405/07/31'])->assertSessionHasErrors('payment_date');
            $this->submit($statement,['method'=>'cheque','amount'=>'100','cheque_number'=>'x'])->assertSessionHasErrors('received_date');
            $this->submit($statement,['method'=>'cheque','amount'=>'100','cheque_number'=>'x','cheque_status'=>'pending'])->assertSessionHasErrors('cheque_status');
            $this->submit($statement,['method'=>'cash','amount'=>'0'])->assertSessionHasErrors('amount');
        }
        $this->assertSame(0,InvoicePayment::count());$this->assertSame(0,CustomerLedger::count());$this->assertSame(0,Cheque::count());
    }
    public function test_cancelled_or_other_customers_invoice_and_unauthorized_users_cannot_register_payment(): void
    {
        DB::table('invoices')->insert(['id'=>2,'uuid'=>'OTHER','customer_id'=>2,'total'=>10000,'status'=>'shipped']);
        $this->post(route('account-statements.payments.store',1),['invoice_id'=>2,'method'=>'cash','amount'=>100])->assertSessionHasErrors('invoice_id');
        DB::table('invoices')->where('id',1)->update(['status'=>Invoice::cancelledStatuses()[0]]);
        foreach([false,true]as$s)$this->submit($s,['method'=>'cash','amount'=>100])->assertSessionHasErrors('invoice');
        $this->financeUser(false);
        foreach([false,true]as$s)$this->submit($s,['method'=>'cash','amount'=>100])->assertForbidden();
        $this->assertSame(0,InvoicePayment::count());$this->assertSame(0,CustomerLedger::count());
    }
    public function test_failure_rolls_back_payment_cheque_and_ledger_together(): void
    {
        $this->app->bind(PaymentRegistrationService::class,fn()=>new class extends PaymentRegistrationService{
            public function registerForInvoice(Invoice $i,array $p,?int $c=null,?int $u=null,?string $r=null,?string $ch=null): InvoicePayment {parent::registerForInvoice($i,$p,$c,$u,$r,$ch);throw new \RuntimeException('Test rollback');}
        });
        foreach([false,true]as$s)$this->submit($s,['method'=>'cheque','amount'=>100,'cheque_number'=>'ROLL','received_date'=>'1405/07/15','due_date'=>'1405/08/15'])->assertStatus(500);
        $this->assertSame(0,InvoicePayment::count());$this->assertSame(0,Cheque::count());$this->assertSame(0,CustomerLedger::count());
    }
    public function test_shared_form_renders_same_fields_and_retains_validation_input(): void
    {
        $this->withSession(['_old_input'=>['_payment_form'=>'paymentModal','method'=>'cheque','amount'=>'۲,۰۰۰','cheque_number'=>'ABC']]);
        request()->setLaravelSession(app('session.store'));
        $html=view('payments.entry-modal',['errors'=>new \Illuminate\Support\ViewErrorBag(),'paymentModalId'=>'paymentModal','paymentAction'=>'/pay','paymentInvoiceOptions'=>collect([['id'=>1,'uuid'=>'PAY-TEST','remaining'=>2000]])])->render();
        foreach(['name="payment_date"','name="tracking_number"','name="due_date"','name="received_date"','value="registered"','value="ABC"','data-remaining="2000"']as$needle)$this->assertStringContainsString($needle,$html);
        $this->assertStringNotContainsString('type="file"',$html);$this->assertStringNotContainsString('value="pending"',$html);
        request()->session()->forget('_old_input');
        $html=view('payments.entry-modal',['errors'=>new \Illuminate\Support\ViewErrorBag(),'paymentModalId'=>'paymentModal','paymentAction'=>'/pay','paymentInvoiceOptions'=>collect([['id'=>1,'uuid'=>'PAY-TEST','remaining'=>2000]])])->render();
        file_put_contents(storage_path('app/payment-unification-20261007/statement-demo.html'), '<!doctype html><html dir="rtl"><head><meta charset="utf-8"><link rel="stylesheet" href="/lib/bootstrap.rtl.min.css"><script src="/lib/bootstrap.bundle.min.js"></script></head><body><button data-bs-toggle="modal" data-bs-target="#paymentModal">افزودن پرداخت</button>'.$html.'</body></html>');
    }

    public function test_overpayment_from_both_pages_credits_the_full_amount_and_allows_more_on_settled_invoice(): void
    {
        foreach([false,true] as $statement){
            DB::table('invoice_payments')->delete();DB::table('customer_ledgers')->delete();
            DB::table('invoices')->where('id',1)->update(['total'=>200000000]);
            DB::table('customer_ledgers')->insert(['customer_id'=>1,'type'=>'debit','amount'=>200000000,'reference_type'=>Invoice::class,'reference_id'=>1]);
            $this->submit($statement,['method'=>'cash','amount'=>'300,000,000'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(300000000,(int)InvoicePayment::sole()->amount);
            $this->assertSame(300000000,(int)CustomerLedger::where('type','credit')->sole()->amount);
            $balance=(int)CustomerLedger::where('type','debit')->sum('amount')-(int)CustomerLedger::where('type','credit')->sum('amount');
            $this->assertSame(-100000000,$balance);
            $this->assertSame(200000000,(int)$this->invoice->fresh()->total);
            $this->submit($statement,['method'=>'cheque','amount'=>'50,000,000','cheque_number'=>'EXTRA','received_date'=>'1405/07/15','due_date'=>'1405/08/15'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(350000000,(int)InvoicePayment::sum('amount'));$this->assertSame(350000000,(int)CustomerLedger::where('type','credit')->sum('amount'));
        }
    }

    public function test_balance_adjustment_restores_route_records_difference_and_audits_without_changing_opening_balance(): void
    {
        DB::table('customers')->where('id',1)->update(['opening_balance'=>500]);
        $route=route('account-statements.adjustments.store',1);
        $payload=['balance_type'=>'credit','target_amount'=>'۲۰۰','expected_balance'=>500,'reason'=>'اصلاح مانده طبق تأیید واحد مالی'];
        $this->post($route,$payload)->assertRedirect(route('account-statements.show',1))->assertSessionHasNoErrors();
        $credit=CustomerLedger::sole();$this->assertSame('credit',$credit->type);$this->assertSame(700,(int)$credit->amount);
        $log=\App\Models\ActivityLog::where('action','customer_balance_adjusted')->sole();
        $this->assertSame(500,$log->properties['balance_before']);$this->assertSame(-200,$log->properties['balance_after']);
        $this->assertSame(1,(int)$log->user_id);$this->assertSame($log->id,$credit->reference_id);
        $this->assertSame(500,(int)Customer::find(1)->opening_balance);
        $this->post($route,$payload)->assertSessionHasErrors('target_amount');$this->assertSame(1,CustomerLedger::count());
        $this->post($route,['balance_type'=>'settled','target_amount'=>'0','expected_balance'=>-200,'reason'=>''])->assertSessionHasErrors('reason');
        $this->post($route,['balance_type'=>'debit','target_amount'=>'100','expected_balance'=>-200,'reason'=>'اصلاح جدید تأییدشده توسط مالی'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(300,(int)CustomerLedger::where('type','debit')->sole()->amount);
        $this->assertSame(2,\App\Models\ActivityLog::where('action','customer_balance_adjusted')->count());
        $this->financeUser(false);
        $this->post($route,['balance_type'=>'settled','target_amount'=>'0','expected_balance'=>100,'reason'=>'اصلاح جدید تأییدشده توسط مالی'])->assertForbidden();
        $this->assertSame(2,CustomerLedger::count());
    }

    public function test_adjustment_modal_and_history_show_audit_text_safely(): void
    {
        $log=new \App\Models\ActivityLog(['properties'=>['balance_before'=>500,'balance_after'=>-200,'reason'=>'اصلاح <script>bad()</script>'],'occurred_at'=>now()]);$log->setRelation('user',new User(['name'=>'مالی']));
        $data=['errors'=>new \Illuminate\Support\ViewErrorBag(),'customer'=>Customer::find(1),'netBalance'=>500,'canAdjustBalance'=>true,'adjustments'=>new \Illuminate\Pagination\LengthAwarePaginator(collect([$log]),1,10)];
        $html=view('account-statements.partials.balance-adjustment',$data)->render();
        foreach(['name="expected_balance"','name="reason"','name="target_amount"','سابقه اصلاح مانده','مالی','بستانکار','&lt;script&gt;']as$n)$this->assertStringContainsString($n,$html);
        $this->assertStringNotContainsString('<script>bad()</script>',$html);
        file_put_contents(storage_path('app/finance-followup-20261007/balance-demo.html'),'<!doctype html><html dir="rtl"><head><meta charset="utf-8"><link rel="stylesheet" href="/lib/bootstrap.rtl.min.css"><link rel="stylesheet" href="/css/payment-entry.css"><script src="/lib/bootstrap.bundle.min.js"></script></head><body><button data-bs-toggle="modal" data-bs-target="#balanceAdjustmentModal">اصلاح مانده حساب</button>'.$html.'</body></html>');
    }
}
