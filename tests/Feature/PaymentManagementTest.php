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

class PaymentManagementTest extends TestCase
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
    private function payment(array $payload = []): InvoicePayment
    {
        $this->post(route('invoices.payments.store','PAY-TEST'), $payload + ['method'=>'cash','amount'=>1000,'payment_date'=>'1405/07/19','tracking_number'=>'OLD'])
            ->assertRedirect()->assertSessionHasNoErrors();
        return InvoicePayment::with('cheque')->latest('id')->first();
    }
    private function version(InvoicePayment $payment): string
    {
        return $this->get(route('account-statements.payments.edit',[1,$payment->id]))->assertOk()->viewData('version');
    }
    private function edit(InvoicePayment $payment, array $payload = [])
    {
        return $this->put(route('account-statements.payments.update',[1,$payment->id]), $payload + [
            'method'=>'cash','amount'=>30000000,'payment_date'=>'1405/07/20','tracking_number'=>'NEW',
            'reason'=>'اصلاح طبق رسید بانکی','version'=>$this->version($payment),
        ]);
    }
    public function test_cash_correction_updates_one_credit_and_preserves_invoice_creator_receipt_and_overpayment(): void
    {
        $p=$this->payment();$p->update(['receipt_image'=>'old.png']);
        $this->edit($p)->assertRedirect()->assertSessionHasNoErrors();
        $p->refresh();$this->assertSame(30000000,(int)$p->amount);$this->assertSame('NEW',$p->payment_identifier);
        $this->assertSame('2026-10-12',$p->paid_at);$this->assertSame('old.png',$p->receipt_image);
        $this->assertSame(1,(int)$p->created_by);$this->assertSame(1,(int)$p->invoice_id);
        $this->assertSame(30000000,(int)CustomerLedger::sole()->amount);$this->assertSame(10000000,(int)$this->invoice->fresh()->total);
        $log=\App\Models\ActivityLog::where('action','invoice_payment_updated')->sole();
        $this->assertSame(1000,$log->properties['before']['amount']);$this->assertSame(30000000,$log->properties['after']['amount']);$this->assertSame(1,(int)$log->user_id);
    }
    public function test_cheque_correction_and_conversion_keep_credit_and_cheque_in_sync(): void
    {
        $p=$this->payment();
        $this->edit($p,['method'=>'cheque','amount'=>2000,'cheque_number'=>'CH1','received_date'=>'1405/07/19','due_date'=>'1405/08/19','bank_name'=>'ملی','cheque_status'=>'registered'])->assertSessionHasNoErrors();
        $p->refresh()->load('cheque');$this->assertSame(2000,(int)$p->cheque->amount);$this->assertSame('CH1',$p->payment_identifier);
        $this->assertSame('2026-11-10',$p->cheque->due_date->format('Y-m-d'));$this->assertSame(2000,(int)CustomerLedger::sole()->amount);
        $p->cheque->update(['image'=>'keep.png','customer_code'=>'CODE']);
        $this->edit($p,['method'=>'cheque','amount'=>4000,'cheque_number'=>'CH2','received_date'=>'1405/07/20','due_date'=>'1405/08/20','cheque_status'=>'registered'])->assertSessionHasNoErrors();
        $p->refresh()->load('cheque');$this->assertSame('keep.png',$p->cheque->image);$this->assertSame('CODE',$p->cheque->customer_code);
        $this->edit($p)->assertSessionHasNoErrors();$this->assertSame(0,Cheque::count());$this->assertSame(1,CustomerLedger::count());
    }
    public function test_delete_removes_financial_rows_once_and_keeps_audit(): void
    {
        $p=$this->payment(['method'=>'cheque','cheque_number'=>'DEL','received_date'=>'1405/07/19','due_date'=>'1405/08/19']);
        $url=route('account-statements.payments.destroy',[1,$p->id]);$payload=['reason'=>'ثبت تکراری رسید مشتری','version'=>$this->version($p)];
        $this->delete($url,$payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0,InvoicePayment::count());$this->assertSame(0,Cheque::count());$this->assertSame(0,CustomerLedger::count());
        $log=\App\Models\ActivityLog::where('action','invoice_payment_deleted')->sole();$this->assertSame('DEL',$log->properties['before']['cheque']['cheque_number']);
        $this->delete($url,$payload)->assertNotFound();$this->assertSame(1,\App\Models\ActivityLog::where('action','invoice_payment_deleted')->count());
    }
    public function test_stale_form_cannot_edit_or_delete_a_changed_payment(): void
    {
        $p=$this->payment();$v=$this->version($p);$this->edit($p)->assertSessionHasNoErrors();
        $this->edit($p,['version'=>$v,'amount'=>9999])->assertSessionHasErrors('version');
        $this->delete(route('account-statements.payments.destroy',[1,$p->id]),['version'=>$v,'reason'=>'حذف پرداخت قدیمی'])->assertSessionHasErrors('version');
        $this->assertSame(30000000,(int)$p->fresh()->amount);$this->assertSame(1,CustomerLedger::count());
    }
    public function test_missing_or_duplicate_or_mismatched_ledger_blocks_all_mutation(): void
    {
        foreach(['missing','duplicate','wrong_amount'] as $case){
            $p=$this->payment();$ledger=CustomerLedger::where('reference_id',$p->id)->sole();
            if($case==='missing')$ledger->delete();elseif($case==='duplicate')$ledger->replicate()->save();else$ledger->update(['amount'=>7]);
            $v=$this->version($p);$this->edit($p,['version'=>$v])->assertSessionHasErrors('payment');
            $this->delete(route('account-statements.payments.destroy',[1,$p->id]),['version'=>$v,'reason'=>'حذف رسید اشتباهی'])->assertSessionHasErrors('payment');
            $this->assertSame(1000,(int)$p->fresh()->amount);
            CustomerLedger::where('reference_id',$p->id)->delete();$p->delete();
        }
        $this->assertSame(0,\App\Models\ActivityLog::whereIn('action',['invoice_payment_updated','invoice_payment_deleted'])->count());
    }
    public function test_validation_customer_scope_and_finance_permissions_protect_payments(): void
    {
        $p=$this->payment();$this->edit($p,['amount'=>0])->assertSessionHasErrors('amount');
        $this->edit($p,['payment_date'=>'1405/07/31'])->assertSessionHasErrors('payment_date');
        $this->edit($p,['reason'=>''])->assertSessionHasErrors('reason');
        DB::table('customers')->insert(['id'=>2,'display_name'=>'دیگر']);
        $this->put(route('account-statements.payments.update',[2,$p->id]),[])->assertNotFound();
        $this->delete(route('account-statements.payments.destroy',[2,$p->id]),[])->assertNotFound();
        $this->financeUser(false);
        $this->get(route('account-statements.payments.edit',[1,$p->id]))->assertForbidden();
        $this->put(route('account-statements.payments.update',[1,$p->id]),[])->assertForbidden();
        $this->delete(route('account-statements.payments.destroy',[1,$p->id]),[])->assertForbidden();
        $this->assertSame(1000,(int)$p->fresh()->amount);$this->assertSame(1,CustomerLedger::count());
    }
    public function test_audit_failure_rolls_back_edit_and_delete(): void
    {
        $p=$this->payment();$v=$this->version($p);
        Schema::drop('activity_logs');
        $this->edit($p,['version'=>$v])->assertStatus(500);$this->assertSame(1000,(int)$p->fresh()->amount);
        $this->delete(route('account-statements.payments.destroy',[1,$p->id]),['version'=>$v,'reason'=>'حذف رسید اشتباهی'])->assertStatus(500);
        $this->assertSame(1,InvoicePayment::count());$this->assertSame(1000,(int)CustomerLedger::sole()->amount);
    }
    public function test_duplicate_cheques_disable_management_and_are_not_silently_deleted(): void
    {
        $p=$this->payment(['method'=>'cheque','cheque_number'=>'DUP','received_date'=>'1405/07/19','due_date'=>'1405/08/19']);
        $p->cheque->replicate()->save();
        $this->get(route('account-statements.payments.edit',[1,$p->id]))->assertOk()->assertViewHas('managementIssue',true);
        $this->edit($p)->assertSessionHasErrors('payment');
        $this->delete(route('account-statements.payments.destroy',[1,$p->id]),['version'=>$this->version($p),'reason'=>'حذف رسید اشتباهی'])->assertSessionHasErrors('payment');
        $this->assertSame(2,Cheque::count());$this->assertSame(1000,(int)$p->fresh()->amount);
    }
    public function test_legacy_method_is_preserved_and_cancelled_invoice_cannot_be_edited(): void
    {
        $p=$this->payment();$p->update(['method'=>'online']);
        $this->edit($p,['method'=>'online'])->assertSessionHasNoErrors();
        $this->assertSame('online',$p->fresh()->method);$this->assertSame('NEW',$p->fresh()->payment_identifier);
        DB::table('invoices')->where('id',1)->update(['status'=>Invoice::cancelledStatuses()[0]]);
        $this->edit($p,['method'=>'online','amount'=>999])->assertSessionHasErrors('invoice');
        $this->assertSame(30000000,(int)$p->fresh()->amount);
    }
}
