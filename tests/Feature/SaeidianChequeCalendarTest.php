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
}
