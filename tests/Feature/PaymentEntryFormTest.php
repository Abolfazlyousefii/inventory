<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentEntryFormTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $user->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $this->invoice = Invoice::query()->create([
            'uuid' => 'PAYMENT-ENTRY-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'total' => 10_000_000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);
    }

    public function test_cash_payment_accepts_grouped_rials_and_defaults_to_today_without_images(): void
    {
        Storage::fake('public');
        $this->post(route('invoices.payments.store', $this->invoice->uuid), [
            'method' => 'cash',
            'amount' => '1,234,567',
            'receipt_image' => UploadedFile::fake()->image('receipt.png'),
        ])->assertRedirect();

        $payment = InvoicePayment::query()->sole();
        $this->assertSame(1_234_567, (int) $payment->amount);
        $this->assertSame(now()->toDateString(), $payment->paid_at);
        $this->assertNull($payment->receipt_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_cheque_payment_uses_received_date_without_payment_date(): void
    {
        Storage::fake('public');
        $this->post(route('account-statements.payments.store', $this->invoice->customer_id), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cheque',
            'amount' => '۲,۰۰۰,۰۰۰',
            'cheque_number' => 'CHK-1',
            'cheque_bank_name' => 'ملی',
            'cheque_branch_name' => 'مرکزی',
            'cheque_account_number' => '123456',
            'cheque_received_at' => '2026-09-20',
            'cheque_due_date' => '2026-10-20',
            'cheque_image' => UploadedFile::fake()->image('cheque.png'),
        ])->assertRedirect();

        $payment = InvoicePayment::query()->with('cheque')->sole();
        $this->assertSame(2_000_000, (int) $payment->amount);
        $this->assertSame('2026-09-20', $payment->paid_at);
        $this->assertSame('2026-09-20', $payment->cheque->received_at->toDateString());
        $this->assertSame('2026-10-20', $payment->cheque->due_date->toDateString());
        $this->assertSame('unregistered', $payment->cheque->status);
        $this->assertSame('ملی', $payment->cheque->bank_name);
        $this->assertSame('مرکزی', $payment->cheque->branch_name);
        $this->assertSame('123456', $payment->cheque->account_number);
        $this->assertNull($payment->cheque->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_cheque_requires_received_and_due_dates(): void
    {
        $this->post(route('invoices.payments.store', $this->invoice->uuid), [
            'method' => 'cheque',
            'amount' => '500000',
            'cheque_number' => 'CHK-2',
        ])->assertSessionHasErrors('received_date');

        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_cash_date_is_editable_and_cheque_ignores_a_legacy_payment_date(): void
    {
        $this->post(route('invoices.payments.store', $this->invoice->uuid), [
            'method' => 'cash',
            'amount' => '100000',
            'payment_date' => '2026-09-15',
        ])->assertRedirect();
        $this->assertSame('2026-09-15', InvoicePayment::query()->firstOrFail()->paid_at);

        $this->post(route('invoices.payments.store', $this->invoice->uuid), [
            'method' => 'cheque',
            'amount' => '200000',
            'payment_date' => '2026-09-01',
            'received_date' => '2026-09-20',
            'due_date' => '2026-10-20',
            'cheque_number' => 'CHK-3',
        ])->assertRedirect();
        $this->assertSame('2026-09-20', InvoicePayment::query()->orderByDesc('id')->firstOrFail()->paid_at);
    }

    public function test_account_statement_payment_form_defaults_cash_date_and_has_no_upload_fields(): void
    {
        $this->invoice->customer->update(['mobile' => '09121234567']);

        $this->get(route('account-statements.show', $this->invoice->customer_id))
            ->assertOk()
            ->assertSee('۰۹۱۲۱۲۳۴۵۶۷')
            ->assertSee('name="paid_at"', false)
            ->assertSee('value="'.now()->toDateString().'"', false)
            ->assertDontSee('name="cheque_customer_code"', false)
            ->assertDontSee('name="receipt_image"', false)
            ->assertDontSee('name="cheque_image"', false);
    }

    public function test_cheque_accepts_only_registered_or_unregistered_status_for_new_payments(): void
    {
        $payload = [
            'method' => 'cheque',
            'amount' => '100000',
            'cheque_number' => 'CHK-STATUS',
            'received_date' => '2026-09-20',
            'due_date' => '2026-10-20',
        ];

        $this->post(route('invoices.payments.store', $this->invoice->uuid), $payload + ['cheque_status' => 'pending'])
            ->assertSessionHasErrors('cheque_status');
        $this->assertSame(0, InvoicePayment::query()->count());

        $this->post(route('invoices.payments.store', $this->invoice->uuid), $payload + ['cheque_status' => 'registered'])
            ->assertRedirect();
        $this->assertSame('registered', InvoicePayment::query()->with('cheque')->sole()->cheque->status);
    }

    public function test_customer_payment_can_be_edited_with_ledger_and_audit_kept_in_sync(): void
    {
        $customerId = $this->invoice->customer_id;
        $this->post(route('account-statements.payments.store', $customerId), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => '1,000,000',
            'paid_at' => '2026-09-20',
        ])->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->get(route('account-statements.show', $customerId))
            ->assertOk()
            ->assertSee('edit-payment-btn', false)
            ->assertSee('delete-payment-btn', false);

        $this->put(route('account-statements.payments.update', [$customerId, $payment]), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cheque',
            'amount' => '۲,۰۰۰,۰۰۰',
            'cheque_number' => 'CHK-EDIT',
            'cheque_received_at' => '2026-09-21',
            'cheque_due_date' => '2026-10-21',
            'cheque_status' => 'registered',
            'reason' => 'اصلاح روش و مبلغ پرداخت',
        ])->assertRedirect(route('account-statements.show', $customerId));

        $payment->refresh()->load('cheque');
        $this->assertSame(2_000_000, (int) $payment->amount);
        $this->assertSame('cheque', $payment->method);
        $this->assertSame('2026-09-21', $payment->paid_at);
        $this->assertSame('registered', $payment->cheque->status);
        $this->assertSame(2_000_000, (int) $payment->cheque->amount);
        $this->assertSame(2_000_000, (int) CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)->sole()->amount);
        $log = ActivityLog::query()->where('action', 'invoice_payment_updated')->sole();
        $this->assertSame(1_000_000, $log->properties['before']['amount']);
        $this->assertSame(2_000_000, $log->properties['after']['amount']);
    }

    public function test_customer_payment_delete_removes_ledger_and_preserves_audit_snapshot(): void
    {
        $customerId = $this->invoice->customer_id;
        $this->post(route('account-statements.payments.store', $customerId), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => '1,000,000',
        ])->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->delete(route('account-statements.payments.destroy', [$customerId, $payment]), [
            'reason' => 'ثبت پرداخت اشتباه بود',
        ])->assertRedirect(route('account-statements.show', $customerId));

        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertSame(0, CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)->count());
        $log = ActivityLog::query()->where('action', 'invoice_payment_deleted')->sole();
        $this->assertSame(1_000_000, $log->properties['before']['amount']);
        $this->assertSame('ثبت پرداخت اشتباه بود', $log->properties['reason']);
    }

    public function test_customer_payment_edit_rejects_overpayment_without_changing_balance(): void
    {
        $customerId = $this->invoice->customer_id;
        $this->post(route('account-statements.payments.store', $customerId), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => '1,000,000',
        ])->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->put(route('account-statements.payments.update', [$customerId, $payment]), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => '11,000,000',
            'reason' => 'تغییر مبلغ پرداخت',
        ])->assertStatus(422);

        $this->assertSame(1_000_000, (int) $payment->fresh()->amount);
        $this->assertSame(1_000_000, (int) CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)->sole()->amount);
    }

    public function test_customer_payment_delete_requires_a_reason_and_matching_customer(): void
    {
        $customerId = $this->invoice->customer_id;
        $this->post(route('account-statements.payments.store', $customerId), [
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => '1,000,000',
        ])->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->delete(route('account-statements.payments.destroy', [$customerId, $payment]), [])
            ->assertSessionHasErrors('reason');
        $otherCustomer = Customer::factory()->create();
        $this->delete(route('account-statements.payments.destroy', [$otherCustomer, $payment]), [
            'reason' => 'پرداخت اشتباه است',
        ])->assertNotFound();

        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame(1, CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)->count());
    }
}
