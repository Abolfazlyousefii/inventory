<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\SaeidianChequeEntry;
use App\Models\User;
use App\Services\ChequeCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaeidianChequeCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        $u = User::factory()->create();
        Role::findOrCreate('finance', 'web');
        $u->assignRole('finance');

        return $u;
    }

    public function test_access_is_restricted(): void
    {
        $this->get('/finance/saeidian-cheques')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/finance/saeidian-cheques')->assertForbidden();
    }

    public function test_issued_entry_and_jalali_validation(): void
    {
        $this->actingAs($this->finance())->post('/finance/saeidian-cheques', ['kind' => 'issued', 'party' => 'گیرنده', 'cheque_number' => '123', 'amount' => '1,000', 'effective_date' => '1405/08/24'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('saeidian_cheque_entries', ['kind' => 'issued', 'amount' => 1000, 'effective_date' => '2026-11-15 00:00:00']);
        $this->post('/finance/saeidian-cheques', ['kind' => 'issued', 'party' => 'x', 'cheque_number' => '2', 'amount' => 100, 'effective_date' => '1405/13/44'])->assertSessionHasErrors('effective_date');
        $this->get('/finance/saeidian-cheques?month=1405/08')->assertOk()->assertSee('تقویم چک‌های سعیدیان');
    }

    public function test_deposit_uses_original_amount_and_cannot_repeat_or_touch_customer_ledger(): void
    {
        $u = $this->finance();
        $invoice = DB::table('invoices')->insertGetId(['uuid' => 'test-cheque']);
        $payment = DB::table('invoice_payments')->insertGetId(['invoice_id' => $invoice, 'method' => 'cheque', 'amount' => 4500]);
        $c = new Cheque;
        $c->forceFill(['invoice_payment_id' => $payment, 'amount' => 4500, 'customer_name' => 'مشتری', 'cheque_number' => '456', 'status' => 'registered', 'due_date' => '1405-07-01']);
        $c->save();
        $this->actingAs($u)->get('/finance/saeidian-cheques')->assertOk();
        $before = DB::table('customer_ledgers')->count();
        $data = ['deposited_confirmation' => '1', 'kind' => 'deposit', 'cheque_id' => $c->id, 'effective_date' => '1405/08/24', 'amount' => 1];
        $this->actingAs($u)->post('/finance/saeidian-cheques', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('saeidian_cheque_entries', ['cheque_id' => $c->id, 'amount' => 4500]);
        $this->post('/finance/saeidian-cheques', $data)->assertSessionHasErrors('cheque_id');
        $this->assertSame($before, DB::table('customer_ledgers')->count());
        $this->assertSame('registered', $c->fresh()->status);
    }

    public function test_coverage_carries_prior_month_and_ignores_future_funding(): void
    {
        $rows = collect([new SaeidianChequeEntry(['kind' => 'deposit', 'amount' => 100, 'effective_date' => '2026-10-01']), new SaeidianChequeEntry(['kind' => 'issued', 'amount' => 150, 'effective_date' => '2026-11-10']), new SaeidianChequeEntry(['kind' => 'deposit', 'amount' => 80, 'effective_date' => '2026-11-20'])]);
        $s = new ChequeCoverage;
        $this->assertSame(50, $s->balance($rows, '2026-11-10')['shortfall']);
        $this->assertSame(30, $s->balance($rows, '2026-11-20')['balance']);
    }

    public function test_promises_are_planning_only_and_include_salary_category(): void
    {
        $this->actingAs($this->finance());
        $before=DB::table('customer_ledgers')->count();
        $this->post('/finance/saeidian-cheques',['kind'=>'promise','party'=>'حقوق آذر','category'=>'salary','amount'=>700000,'effective_date'=>'1405/09/28'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('saeidian_cheque_entries',['kind'=>'promise','category'=>'salary','amount'=>700000]);
        $this->assertSame($before,DB::table('customer_ledgers')->count());
    }

    public function test_coverage_uses_due_date_without_rewriting_the_deposit_date(): void
    {
        $u=$this->finance();
        $invoice=DB::table('invoices')->insertGetId(['uuid'=>'due-date-qa']);
        $payment=DB::table('invoice_payments')->insertGetId(['invoice_id'=>$invoice,'method'=>'cheque','amount'=>4500]);
        $cheque=Cheque::create(['invoice_payment_id'=>$payment,'amount'=>4500,'customer_name'=>'تست','cheque_number'=>'DUE','status'=>'registered','due_date'=>'1405-09-10']);
        $entry=SaeidianChequeEntry::create(['kind'=>'deposit','cheque_id'=>$cheque->id,'amount'=>4500,'effective_date'=>'2026-11-15','party'=>'تست','created_by'=>$u->id]);
        $coverage=new ChequeCoverage;
        $this->assertSame(0,$coverage->balance(collect([$entry]),'2026-11-21')['incoming']);
        $this->assertSame(4500,$coverage->balance(collect([$entry]),'2026-12-01')['incoming']);
        $this->actingAs($u)->get('/finance/saeidian-cheques?month=1405/09')->assertOk()->assertViewHas('monthly',fn($rows)=>$rows->contains('id',$entry->id));
        $this->assertSame('2026-11-15',$entry->fresh()->effective_date->toDateString());
    }

    public function test_bounced_outgoing_remains_liability_but_bounced_incoming_is_excluded(): void
    {
        $rows=collect([new SaeidianChequeEntry(['kind'=>'issued','status'=>'bounced','amount'=>150,'effective_date'=>'2026-11-10']),new SaeidianChequeEntry(['kind'=>'promise','category'=>'insurance','amount'=>50,'effective_date'=>'2026-11-10']),new SaeidianChequeEntry(['kind'=>'deposit','status'=>'bounced','amount'=>100,'effective_date'=>'2026-11-10'])]);
        $this->assertSame(200,(new ChequeCoverage)->balance($rows,'2026-11-10')['shortfall']);
    }

    public function test_bounce_is_authorized_audited_and_requires_reason_and_current_version(): void
    {
        $u=$this->finance();$entry=SaeidianChequeEntry::create(['kind'=>'issued','party'=>'تست','cheque_number'=>'BOUNCE','amount'=>100,'effective_date'=>'2026-11-10','created_by'=>$u->id]);
        $url='/finance/saeidian-cheques/'.$entry->id.'/status';
        $this->actingAs(User::factory()->create())->patch($url,['status'=>'bounced','reason'=>'تست','expected_status'=>'active'])->assertForbidden();
        $this->actingAs($u)->patch($url,['status'=>'bounced','expected_status'=>'active'])->assertSessionHasErrors('reason');
        $this->patch($url,['status'=>'bounced','reason'=>'عدم پرداخت بانک','expected_status'=>'active'])->assertSessionHasNoErrors();
        $this->assertSame('bounced',$entry->fresh()->status);
        $this->assertDatabaseHas('activity_logs',['action'=>'saeidian_cheque.status_changed','subject_id'=>$entry->id]);
        $this->patch($url,['status'=>'active','reason'=>'بررسی مجدد','expected_status'=>'active'])->assertSessionHasErrors('status');
    }

    public function test_retries_cannot_duplicate_a_promise_and_invalid_category_is_rejected(): void
    {
        $this->actingAs($this->finance());
        $data=['kind'=>'promise','party'=>'بیمه','category'=>'insurance','amount'=>100,'effective_date'=>'1405/09/05','submission_token'=>(string)\Illuminate\Support\Str::uuid()];
        $this->post('/finance/saeidian-cheques',$data)->assertSessionHasNoErrors();
        $this->post('/finance/saeidian-cheques',$data)->assertSessionHasErrors('submission_token');
        $this->assertSame(1,SaeidianChequeEntry::count());
        $data['submission_token']=(string)\Illuminate\Support\Str::uuid();$data['category']='invalid';
        $this->post('/finance/saeidian-cheques',$data)->assertSessionHasErrors('category');
    }
    public function test_new_layout_is_rendered_for_ajax_month_changes_and_contains_native_details_popup(): void
    {
        $this->actingAs($this->finance())->get('/finance/saeidian-cheques?month=1405/09',['X-Requested-With'=>'XMLHttpRequest'])
            ->assertOk()->assertSee('خلاصه ماه')->assertSee('ثبت تعهد')->assertSee('<dialog id="dayDetails"',false)->assertSee('ماه قبل');
    }

    public function test_source_bounced_cheque_cannot_be_restored_by_the_planning_report(): void
    {
        $u=$this->finance();
        $invoice=DB::table('invoices')->insertGetId(['uuid'=>'source-bounce-qa']);
        $payment=DB::table('invoice_payments')->insertGetId(['invoice_id'=>$invoice,'method'=>'cheque','amount'=>4500]);
        $cheque=Cheque::create(['invoice_payment_id'=>$payment,'amount'=>4500,'customer_name'=>'تست','cheque_number'=>'SOURCE','status'=>'bounced','due_date'=>'2026-11-20']);
        $entry=SaeidianChequeEntry::create(['kind'=>'deposit','cheque_id'=>$cheque->id,'amount'=>4500,'status'=>'bounced','effective_date'=>'2026-11-15','party'=>'تست','created_by'=>$u->id]);
        $this->actingAs($u)->patch('/finance/saeidian-cheques/'.$entry->id.'/status',['status'=>'active','expected_status'=>'bounced','reason'=>'بررسی مجدد'])->assertSessionHasErrors('status');
        $this->assertSame('bounced',$entry->fresh()->status);
        $this->assertSame('bounced',$cheque->fresh()->status);
        $this->assertSame(0,(new ChequeCoverage)->balance(collect([$entry]),'2026-11-30')['incoming']);
    }

    public function test_management_page_with_entries_and_legacy_dates_renders(): void
    {
        $this->withoutVite();
        $u=$this->finance();
        foreach ([['issued',null,'نمونه چک تأمین‌کننده',180000000,'active'],['promise','salary','حقوق کارکنان',90000000,'active'],['promise','insurance','بیمه کارکنان',30000000,'active'],['issued',null,'نمونه چک برگشتی',20000000,'bounced']] as [$kind,$category,$party,$amount,$status]) {
            SaeidianChequeEntry::create(['kind'=>$kind,'category'=>$category,'party'=>$party,'amount'=>$amount,'status'=>$status,'cheque_number'=>$kind==='issued'?'123456':null,'effective_date'=>'2026-11-20','created_by'=>$u->id]);
        }
        $response=$this->actingAs($u)->get('/finance/saeidian-cheques?month=1405/08')->assertOk()->assertSee('حقوق کارکنان')->assertSee('بیمه کارکنان')->assertSee('برگشتی');
        if(getenv('SAEIDIAN_PREVIEW')) file_put_contents(base_path('tmp/saeidian-management-preview.html'),$response->getContent());
    }
}
