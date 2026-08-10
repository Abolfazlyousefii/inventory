<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLedgerCancelledInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_invoice_is_not_counted_but_active_invoices_and_payments_are(): void
    {
        $customer = Customer::query()->create(['first_name' => 'محمد', 'last_name' => 'احمدی', 'mobile' => '09120000001', 'opening_balance' => 0]);

        $activeOne = Invoice::query()->create(['uuid' => 'INV-1', 'customer_id' => $customer->id, 'customer_name' => 'محمد احمدی', 'total' => 253_090_000, 'status' => Invoice::STATUS_SHIPPED]);
        $activeTwo = Invoice::query()->create(['uuid' => 'INV-2', 'customer_id' => $customer->id, 'customer_name' => 'محمد احمدی', 'total' => 41_770_000, 'status' => Invoice::STATUS_PENDING_COLLECTION]);
        $cancelled = Invoice::query()->create(['uuid' => 'INV-3', 'customer_id' => $customer->id, 'customer_name' => 'محمد احمدی', 'total' => 80_030_000, 'status' => Invoice::STATUS_NOT_SHIPPED]);
        $payment = InvoicePayment::query()->create(['invoice_id' => $activeOne->id, 'customer_id' => $customer->id, 'amount' => 10_000_000, 'method' => 'cash', 'paid_at' => now()]);

        foreach ([$activeOne, $activeTwo, $cancelled] as $invoice) {
            CustomerLedger::query()->create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => $invoice->total, 'reference_type' => Invoice::class, 'reference_id' => $invoice->id]);
        }
        CustomerLedger::query()->create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => $payment->amount, 'reference_type' => InvoicePayment::class, 'reference_id' => $payment->id]);

        $customer = Customer::query()->withBalance()->findOrFail($customer->id);

        $this->assertSame(284_860_000, $customer->balance);
    }
}
