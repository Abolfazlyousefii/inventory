<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountStatementChronologyTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Invoice $paymentInvoice;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $this->customer = Customer::factory()->create(['opening_balance' => 1000]);
        $this->paymentInvoice = Invoice::withoutEvents(fn () => Invoice::create([
            'uuid' => 'CHRONO-PAYMENTS', 'customer_id' => $this->customer->id,
            'total' => 0, 'status' => Invoice::STATUS_SHIPPED,
        ]));
    }

    private function entry(string $registeredAt, int $amount, string $type = 'debit', ?string $paidAt = null, bool $payment = false): CustomerLedger
    {
        $reference = $payment ? InvoicePayment::query()->create([
            'invoice_id' => $this->paymentInvoice->id,
            'customer_id' => $this->customer->id,
            'method' => 'cash',
            'amount' => $amount,
            'paid_at' => $paidAt,
        ]) : null;
        $entry = new CustomerLedger([
            'customer_id' => $this->customer->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $reference ? InvoicePayment::class : null,
            'reference_id' => $reference?->id,
        ]);
        $entry->created_at = $registeredAt;
        $entry->updated_at = $registeredAt;
        $entry->save();

        return $entry;
    }

    private function statement(int $page = 1)
    {
        return $this->get(route('account-statements.show', ['customer' => $this->customer, 'page' => $page]))->assertOk();
    }

    public function test_late_registered_payments_are_grouped_with_their_document_dates_and_balances_follow_that_order(): void
    {
        $sixth = $this->entry('2026-09-01 10:00:00', 100);
        $seventh = $this->entry('2026-10-01 10:00:00', 200);
        $eighth = $this->entry('2026-10-24 10:00:00', 300);
        $lateSeventh = $this->entry('2026-10-24 11:00:00', 50, 'credit', '2026-10-01', true);
        $lateSixth = $this->entry('2026-10-24 12:00:00', 40, 'credit', '2026-09-01', true);
        $before = DB::table('customer_ledgers')->orderBy('id')->get()->toJson();

        $response = $this->statement();
        $this->assertSame([$eighth->id, $lateSeventh->id, $seventh->id, $lateSixth->id, $sixth->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame([
            $eighth->id => 1510, $lateSeventh->id => 1210,
            $seventh->id => 1260, $lateSixth->id => 1060, $sixth->id => 1100,
        ], $response['ledgerBalances']);
        $this->assertSame(1510, $response['netBalance']);
        $response->assertSee('بر اساس تاریخ سند');
        $this->assertSame($before, DB::table('customer_ledgers')->orderBy('id')->get()->toJson());
    }

    public function test_backdated_entries_on_page_two_keep_the_correct_running_balance(): void
    {
        $oldDebit = $this->entry('2026-09-01 10:00:00', 100);
        $oldPayment = $this->entry('2026-10-24 20:00:00', 30, 'credit', '2026-09-01', true);
        $newer = [];
        for ($n = 0; $n < 25; $n++) {
            $newer[] = $this->entry(sprintf('2026-10-24 10:%02d:00', $n), 10);
        }

        $page1 = $this->statement();
        $page2 = $this->statement(2);
        $this->assertSame(array_reverse(array_map(fn ($row) => $row->id, $newer)), $page1['ledgers']->pluck('id')->all());
        $this->assertSame([$oldPayment->id, $oldDebit->id], $page2['ledgers']->pluck('id')->all());
        $this->assertSame([$oldPayment->id => 1070, $oldDebit->id => 1100], $page2['ledgerBalances']);
        $this->assertSame(1320, $page2['netBalance']);
    }

    public function test_changing_a_payment_date_moves_it_without_rewriting_its_registration_date(): void
    {
        $debit = $this->entry('2026-10-01 10:00:00', 100);
        $payment = $this->entry('2026-10-24 10:00:00', 50, 'credit', '2026-10-24', true);
        $originalTimestamp = $payment->created_at->toDateTimeString();
        InvoicePayment::query()->whereKey($payment->reference_id)->update(['paid_at' => '2026-09-01']);

        $response = $this->statement();
        $this->assertSame([$debit->id, $payment->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame([$debit->id => 1050, $payment->id => 950], $response['ledgerBalances']);
        $this->assertSame($originalTimestamp, $payment->fresh()->created_at->toDateTimeString());
    }

    public function test_equal_document_dates_use_registration_time_then_id_as_stable_tiebreakers(): void
    {
        $first = $this->entry('2026-10-24 10:00:00', 10, 'credit', '2026-09-01', true);
        $second = $this->entry('2026-10-24 10:00:00', 20, 'credit', '2026-09-01', true);
        $older = $this->entry('2026-10-23 10:00:00', 30, 'credit', '2026-09-01', true);
        $response = $this->statement();
        $this->assertSame([$second->id, $first->id, $older->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame([$second->id => 940, $first->id => 960, $older->id => 970], $response['ledgerBalances']);
    }

    public function test_missing_payment_or_missing_payment_date_uses_original_ledger_date(): void
    {
        $dated = $this->entry('2026-10-24 09:00:00', 10, 'credit', '2026-09-01', true);
        $undated = $this->entry('2026-10-01 10:00:00', 20, 'credit', null, true);
        $missing = $this->entry('2026-10-02 10:00:00', 30, 'credit');
        $missing->update(['reference_type' => InvoicePayment::class, 'reference_id' => 999999]);
        $response = $this->statement();
        $this->assertSame([$missing->id, $undated->id, $dated->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame(940, $response['netBalance']);
    }

    public function test_cancelled_invoice_does_not_affect_balances_after_document_date_sorting(): void
    {
        $invoice = Invoice::create(['uuid' => 'CHRONO-CANCELLED', 'customer_id' => $this->customer->id, 'total' => 9000, 'status' => Invoice::STATUS_NOT_SHIPPED]);
        $debit = $this->entry('2026-10-01 10:00:00', 100);
        $excluded = $this->entry('2026-10-02 10:00:00', 9000);
        $excluded->update(['reference_type' => Invoice::class, 'reference_id' => $invoice->id]);
        $payment = $this->entry('2026-10-24 10:00:00', 50, 'credit', '2026-09-01', true);
        $response = $this->statement();
        $this->assertSame([$excluded->id, $debit->id, $payment->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame([$excluded->id => 1050, $debit->id => 1050, $payment->id => 950], $response['ledgerBalances']);
        $this->assertSame(1050, $response['netBalance']);
    }

    public function test_same_day_ties_across_pages_have_no_missing_entries_or_balance_jump(): void
    {
        $ids = [];
        for ($n = 0; $n < 30; $n++) {
            $ids[] = $this->entry('2026-10-24 10:00:00', 1, 'credit', '2026-09-01', true)->id;
        }
        $response = $this->statement(2);
        $this->assertSame(array_reverse(array_slice($ids, 0, 5)), $response['ledgers']->pluck('id')->all());
        $this->assertSame(array_combine(array_reverse(array_slice($ids, 0, 5)), range(995, 999)), $response['ledgerBalances']);
    }

    public function test_null_registration_timestamps_are_stable_and_other_customers_are_excluded(): void
    {
        $first = $this->entry('2026-09-01 10:00:00', 10);
        $second = $this->entry('2026-09-01 10:00:00', 20);
        DB::table('customer_ledgers')->whereIn('id', [$first->id, $second->id])->update(['created_at' => null]);
        $dated = $this->entry('2026-10-01 10:00:00', 30);
        $other = Customer::factory()->create();
        CustomerLedger::create(['customer_id' => $other->id, 'type' => 'debit', 'amount' => 999999]);
        $response = $this->statement();
        $this->assertSame([$dated->id, $second->id, $first->id], $response['ledgers']->pluck('id')->all());
        $this->assertSame([$dated->id => 1060, $second->id => 1030, $first->id => 1010], $response['ledgerBalances']);

        $rows = app(\App\Services\AccountStatementPresentationService::class)->entries($this->customer)
            ->orderByDesc('statement_date')->orderByDesc('created_at')->orderByDesc('id')->skip(1)->take(2)->get();
        $ids = collect([$first->id => true, $second->id => true]);
        $this->assertSame([$second->id => 1030, $first->id => 1010], app(\App\Services\AccountStatementPresentationService::class)->pageBalances($this->customer, $rows, $ids, 1060));
    }
}
