<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\SalesPrintDocumentService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InvoiceCustomerAddressRegressionTest extends TestCase
{
    public function test_customer_print_reads_the_persons_address_if_invoice_address_is_blank(): void
    {
        $invoice = $this->invoiceWithAddress('  ', 'تهران، خیابان آزادی، پلاک ۲۵');

        $this->assertSame('تهران، خیابان آزادی، پلاک ۲۵', $invoice->display_customer_address);

        $printData = app(SalesPrintDocumentService::class)->invoiceData($invoice, 'customer');
        $this->assertSame('تهران، خیابان آزادی، پلاک ۲۵', $printData['customer']['address']);
        $this->assertSame('customer', $printData['mode']);
    }

    public function test_explicit_invoice_delivery_address_is_preferred_over_the_current_persons_address(): void
    {
        $invoice = $this->invoiceWithAddress('مشهد، نشانی هنگام صدور', 'تهران، نشانی جدید اشخاص');

        $this->assertSame('مشهد، نشانی هنگام صدور', $invoice->display_customer_address);
        $this->assertSame(
            'مشهد، نشانی هنگام صدور',
            app(SalesPrintDocumentService::class)->invoiceData($invoice, 'customer')['customer']['address']
        );
    }

    public function test_invoice_address_is_empty_if_neither_document_nor_person_has_one(): void
    {
        $invoice = $this->invoiceWithAddress(null, '  ');
        $this->assertSame('', $invoice->display_customer_address);
    }

    public function test_invoice_view_and_legacy_print_use_the_same_display_address(): void
    {
        foreach ([
            'invoices/show.blade.php',
            'vouchers/sales/show.blade.php',
            'invoices/print.blade.php',
            'account-statements/documents/invoice-view.blade.php',
        ] as $path) {
            $view = file_get_contents(resource_path('views/'.$path));
            $this->assertStringContainsString('display_customer_address', $view, $path);
        }

        $printService = file_get_contents(app_path('Services/SalesPrintDocumentService.php'));
        $this->assertStringContainsString("'address' => \$invoice->display_customer_address", $printService);
    }

    private function invoiceWithAddress(?string $documentAddress, string $personsAddress): Invoice
    {
        $invoice = new Invoice([
            'uuid' => 'TEST-PRINT-ADDRESS-01',
            'customer_address' => $documentAddress,
            'customer_name' => 'مشتری تست',
            'total' => 0,
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        $invoice->setRelation('customer', new Customer(['address' => $personsAddress]));
        $invoice->setRelation('items', new Collection());
        $invoice->setRelation('payments', new Collection());
        $invoice->setRelation('preinvoiceOrder', null);
        $invoice->setRelation('shippingMethod', null);

        return $invoice;
    }
}
