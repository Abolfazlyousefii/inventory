<?php

use App\Models\{ActivityLog, Customer, CustomerLedger, Invoice, User};
use App\Services\PaymentRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->customer = Customer::factory()->create(['opening_balance' => 1000]);
    $this->actingAs($this->actor);
});

function statementAdjustmentDocument($test): ActivityLog
{
    $test->post(route('account-statements.adjustments.store', $test->customer), [
        'balance_type' => 'debit', 'target_amount' => '1200', 'expected_balance' => 1000,
        'reason' => 'اصلاح آزمایشی طبق تأیید مالی',
    ])->assertRedirect();
    return ActivityLog::where('action', 'customer_balance_adjusted')->sole();
}

it('edits only the adjustment delta and deletes its effect while preserving subsequent movements and audit', function () {
    $doc = statementAdjustmentDocument($this);
    $ledger = CustomerLedger::findOrFail($doc->properties['ledger_id']);
    CustomerLedger::create(['customer_id' => $this->customer->id, 'type' => 'credit', 'amount' => 50, 'note' => 'دریافت بعدی']);
    $edit = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->assertOk();
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), [
        'type' => 'debit', 'amount' => 300, 'reason' => 'افزایش مبلغ سند اصلاحی با تأیید مالی', 'version' => $edit->viewData('version'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect((int) Customer::withBalance()->find($this->customer->id)->balance)->toBe(1250);
    expect((int) $ledger->fresh()->amount)->toBe(300);
    expect($doc->fresh()->properties['balance_after'])->toBe(1200);
    expect(ActivityLog::where('action', 'customer_balance_adjustment_updated')->sole()->properties['balance_after'])->toBe(1250);
    $version = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->viewData('version');
    $this->delete(route('account-statements.adjustments.destroy', [$this->customer, $doc]), [
        'version' => $version, 'reason' => 'حذف سند اصلاحی پس از بررسی مالی',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(Customer::withBalance()->find($this->customer->id)->balance)->toBe(950);
    expect($ledger->fresh())->toBeNull();
    expect($doc->fresh()->properties['deleted_at'])->not->toBeNull();
    expect(ActivityLog::where('action', 'customer_balance_adjustment_deleted')->count())->toBe(1);
    $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->assertNotFound();
});

it('can change an adjustment from debit to credit', function () {
    $doc = statementAdjustmentDocument($this);
    $version = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->viewData('version');
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), [
        'type' => 'credit', 'amount' => '۳۰۰', 'reason' => 'تغییر نوع سند طبق تأیید واحد مالی', 'version' => $version,
    ])->assertSessionHasNoErrors();
    expect(Customer::withBalance()->find($this->customer->id)->balance)->toBe(700);
});

it('rejects stale changes and deletion without losing the current document', function () {
    $doc = statementAdjustmentDocument($this);
    $version = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->viewData('version');
    CustomerLedger::find($doc->properties['ledger_id'])->update(['amount' => 400]);
    $data = ['type' => 'credit', 'amount' => 500, 'reason' => 'آزمایش درخواست همزمان اصلاح سند', 'version' => $version];
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), $data)->assertSessionHasErrors('version');
    $this->delete(route('account-statements.adjustments.destroy', [$this->customer, $doc]), $data)->assertSessionHasErrors('version');
    expect((int) CustomerLedger::find($doc->properties['ledger_id'])->amount)->toBe(400);
});

it('rejects cross-customer access, unrelated logs, zero amounts and missing reasons', function () {
    $doc = statementAdjustmentDocument($this);
    $other = Customer::factory()->create();
    $this->get(route('account-statements.adjustments.edit', [$other, $doc]))->assertNotFound();
    $version = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->viewData('version');
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), ['type' => 'debit', 'amount' => 0, 'version' => $version])->assertSessionHasErrors(['amount', 'reason']);
    $this->delete(route('account-statements.adjustments.destroy', [$this->customer, $doc]), ['version' => $version])->assertSessionHasErrors('reason');
    $doc->update(['action' => 'unrelated_event']);
    $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->assertNotFound();
});

it('denies all adjustment writes to read-only finance users', function () {
    $doc = statementAdjustmentDocument($this);
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Manager', 'web'), Role::findOrCreate(\App\Support\AccountStatementAccess::ROLE, 'web'));
    $this->actingAs($user);
    $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->assertForbidden();
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), [])->assertForbidden();
    $this->delete(route('account-statements.adjustments.destroy', [$this->customer, $doc]), [])->assertForbidden();
});

it('allows online site payment correction and deletion without changing the invoice', function () {
    $invoice = Invoice::create(['uuid' => 'SITE-PAY-TEST', 'customer_id' => $this->customer->id,
        'external_order_id' => 9911, 'total' => 2000, 'status' => Invoice::STATUS_SHIPPED]);
    $payment = app(PaymentRegistrationService::class)->registerForInvoice($invoice,
        ['method' => 'online', 'amount' => 200, 'paid_at' => '2026-11-01', 'payment_identifier' => 'SITE-REF']);
    $edit = $this->get(route('account-statements.payments.edit', [$this->customer, $payment]))->assertOk();
    $this->put(route('account-statements.payments.update', [$this->customer, $payment]), [
        'method' => 'online', 'amount' => 2000, 'payment_date' => '2026/11/01', 'tracking_number' => 'SITE-REF',
        'reason' => 'اصلاح واحد تومان به ریال', 'version' => $edit->viewData('version'), 'return_to' => 'account-statement',
    ])->assertSessionHasNoErrors()->assertRedirect(route('account-statements.show', $this->customer));
    expect((int) $invoice->fresh()->total)->toBe(2000);
    expect((int) $payment->fresh()->amount)->toBe(2000);
    expect((int) CustomerLedger::where('reference_id', $payment->id)->where('reference_type', \App\Models\InvoicePayment::class)->sole()->amount)->toBe(2000);
    $version = $this->get(route('account-statements.payments.edit', [$this->customer, $payment]))->viewData('version');
    $this->put(route('account-statements.payments.update', [$this->customer, $payment]), [
        'method' => 'cash', 'amount' => 2000, 'payment_date' => '2026/11/01', 'reason' => 'تغییر روش پرداخت سایت', 'version' => $version,
    ])->assertSessionHasErrors('method');
    $this->delete(route('account-statements.payments.destroy', [$this->customer, $payment]), [
        'reason' => 'حذف پرداخت آنلاین تکراری', 'version' => $version,
    ])->assertSessionHasNoErrors();
    expect($payment->fresh())->toBeNull();
    expect((int) $invoice->fresh()->total)->toBe(2000);
    expect(ActivityLog::where('action', 'invoice_payment_deleted')->count())->toBe(1);
});

it('rejects inconsistent adjustment ledgers and cross-customer update and delete attempts', function () {
    $doc = statementAdjustmentDocument($this);
    $other = Customer::factory()->create();
    $version = $this->get(route('account-statements.adjustments.edit', [$this->customer, $doc]))->viewData('version');
    $data = ['type' => 'debit', 'amount' => 500, 'reason' => 'بررسی سند اصلاح مانده مشتری', 'version' => $version];
    $this->put(route('account-statements.adjustments.update', [$other, $doc]), $data)->assertNotFound();
    $this->delete(route('account-statements.adjustments.destroy', [$other, $doc]), $data)->assertNotFound();
    CustomerLedger::create(['customer_id' => $this->customer->id, 'type' => 'debit', 'amount' => 200,
        'reference_type' => ActivityLog::class, 'reference_id' => $doc->id]);
    $this->put(route('account-statements.adjustments.update', [$this->customer, $doc]), $data)->assertSessionHasErrors('adjustment');
    $this->delete(route('account-statements.adjustments.destroy', [$this->customer, $doc]), $data)->assertSessionHasErrors('adjustment');
    expect(CustomerLedger::where('reference_type', ActivityLog::class)->count())->toBe(2);
});

it('allows correction of actual site receipts after order cancellation without restoring its invoice', function () {
    $invoice = Invoice::create(['uuid' => 'SITE-CANCEL-PAY', 'customer_id' => $this->customer->id,
        'external_order_id' => 9912, 'total' => 2000, 'status' => Invoice::STATUS_NOT_SHIPPED]);
    $payment = app(PaymentRegistrationService::class)->registerForInvoice($invoice,
        ['method' => 'online', 'amount' => 200, 'paid_at' => '2026-11-01']);
    $version = $this->get(route('account-statements.payments.edit', [$this->customer, $payment]))->assertOk()->viewData('version');
    $this->put(route('account-statements.payments.update', [$this->customer, $payment]), [
        'method' => 'online', 'amount' => 2000, 'payment_date' => '2026/11/01',
        'reason' => 'اصلاح رسید واقعی پس از لغو سفارش', 'version' => $version,
    ])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_NOT_SHIPPED);
    expect((int) $invoice->fresh()->total)->toBe(2000);
    expect(Customer::withBalance()->find($this->customer->id)->balance)->toBe(-1000);
});
