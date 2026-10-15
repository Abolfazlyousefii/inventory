<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\SalesPrintDocumentService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CustomerInvoicePrintBalanceTest extends TestCase
{
    public function test_customer_invoice_shows_ledger_balance_separate_from_this_invoices_remaining(): void
    {
        $invoice = $this->invoiceWithCustomer(150_000, 50_000, 20_000);

        $data = app(SalesPrintDocumentService::class)->invoiceData($invoice, 'customer');

        $this->assertSame(180_000, $data['accountBalance']['amount']);
        $this->assertSame('بدهکار', $data['accountBalance']['status']);
        $this->assertSame('customer', $data['mode']);
        $html = view('prints.partials.sales-invoice-template', ['printData' => $data])->render();
        $this->assertStringContainsString('مانده کل حساب مشتری', $html);
        $this->assertStringContainsString('بدهکار', $html);
        $this->assertStringContainsString('180,000', $html);
        $this->assertStringContainsString('مانده', $html); // Existing invoice-specific remaining is retained.
    }

    public function test_creditor_and_settled_customer_balances_have_correct_labels(): void
    {
        $invoice = $this->invoiceWithCustomer(0, 20_000, 75_000);
        $credit = app(SalesPrintDocumentService::class)->invoiceData($invoice, 'customer');
        $this->assertSame(55_000, $credit['accountBalance']['amount']);
        $this->assertSame('بستانکار', $credit['accountBalance']['status']);

        $settled = $this->invoiceWithCustomer(5_000, 20_000, 25_000);
        $data = app(SalesPrintDocumentService::class)->invoiceData($settled, 'customer');
        $this->assertSame(0, $data['accountBalance']['amount']);
        $this->assertSame('تسویه', $data['accountBalance']['status']);
    }

    public function test_payment_information_is_printed_only_on_customer_invoice_copy(): void
    {
        config()->set('customer_invoice_payment', [
            'name' => 'Test Payee',
            'national_id' => '1234567890',
            'iban' => 'IR123456789012345678901234',
            'account' => '12345678',
            'card' => '1234-5678-9012-3456',
        ]);

        $invoice = $this->invoiceWithCustomer(50_000, 20_000, 10_000);
        $service = app(SalesPrintDocumentService::class);
        $customerData = $service->invoiceData($invoice, 'customer');
        $customerHtml = view('prints.partials.sales-invoice-template', [
            'printData' => $customerData,
        ])->render();

        $this->assertStringContainsString('مشخصات واریز', $customerHtml);
        foreach (['Test Payee', '1234567890', 'IR123456789012345678901234', '12345678', '1234-5678-9012-3456'] as $value) {
            $this->assertStringContainsString($value, $customerHtml);
        }

        $warehouseData = $service->invoiceData($invoice, 'warehouse');
        $this->assertNull($warehouseData['accountBalance']);
        $this->assertNull($warehouseData['customerPayment']);
        $warehouseHtml = view('prints.partials.sales-invoice-template', [
            'printData' => $warehouseData,
        ])->render();
        $this->assertStringNotContainsString('مشخصات واریز', $warehouseHtml);
        $this->assertStringNotContainsString('Test Payee', $warehouseHtml);
        $this->assertStringNotContainsString('مانده کل حساب مشتری', $warehouseHtml);
    }

    public function test_unlinked_invoice_does_not_claim_a_zero_account_balance(): void
    {
        $invoice = $this->invoiceWithCustomer(0, 0, 0);
        $invoice->setRelation('customer', null);
        $invoice->customer_id = null;

        $data = app(SalesPrintDocumentService::class)->invoiceData($invoice, 'customer');
        $this->assertNull($data['accountBalance']);
        $html = view('prints.partials.sales-invoice-template', ['printData' => $data])->render();
        $this->assertStringNotContainsString('مانده کل حساب مشتری', $html);
    }

    private function invoiceWithCustomer(int $opening, int $debit, int $credit): Invoice
    {
        $customer = new Customer([
            'name' => 'Sample customer',
            'opening_balance' => $opening,
        ]);
        // Use the same pre-aggregated attributes as Customer::withBalance().
        $customer->setAttribute('debit_sum', $debit);
        $customer->setAttribute('credit_sum', $credit);

        $invoice = new Invoice([
            'uuid' => 'PRINT-BALANCE-TEST',
            'customer_name' => 'Sample customer',
            'total' => 0,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('items', new Collection());
        $invoice->setRelation('payments', new Collection());
        $invoice->setRelation('preinvoiceOrder', null);
        $invoice->setRelation('shippingMethod', null);

        return $invoice;
    }
}
