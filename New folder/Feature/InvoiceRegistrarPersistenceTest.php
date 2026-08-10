<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Services\SalesDocumentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceRegistrarPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_registrar_is_persisted_and_recovered_through_preinvoice_relation(): void
    {
        $registrar = User::factory()->create();
        $order = $this->order($registrar);
        $invoice = $this->invoice($order, null);

        $this->assertFalse(Schema::hasColumn('invoices', 'created_by'));
        $this->assertFalse(Schema::hasColumn('invoices', 'registered_by'));
        $this->assertSame($registrar->id, $invoice->preinvoiceOrder->created_by);
        $this->assertTrue(app(SalesDocumentAccessService::class)->isInvoiceOwner($invoice, $registrar));

        $controllerSource = file_get_contents(app_path('Http/Controllers/PreinvoiceController.php'));
        $serviceSource = file_get_contents(app_path('Services/SalesHavalehService.php'));
        $this->assertStringContainsString("'preinvoice_order_id' => \$order->id", $controllerSource);
        $this->assertStringContainsString("'preinvoice_order_id' => \$order->id", $serviceSource);
    }

    public function test_read_only_audit_reports_attributable_and_unknown_invoices(): void
    {
        $registrar = User::factory()->create();
        $this->invoice($this->order($registrar), null);
        Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'document_date' => now(),
            'customer_name' => 'فاکتور مستقیم',
            'subtotal' => 2000,
            'total' => 2000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        $this->artisan('sales:audit-invoice-registrars', ['--user' => $registrar->id])
            ->assertSuccessful();

        $report = json_decode(file_get_contents(storage_path('logs/invoice-registrar-audit.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('preinvoice_orders.created_by -> users.id', $report['registrar_contract']);
        $this->assertNull($report['invoice_direct_registrar_column']);
        $this->assertSame(1, $report['invoices_attributable_via_preinvoice']);
        $this->assertSame(1, $report['invoices_without_valid_registrar']);
        $this->assertSame(1, $report['invoices_for_inspected_user']);
    }

    private function order(User $registrar): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $registrar->id,
            'seller_id' => null,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);
    }

    private function invoice(PreinvoiceOrder $order, ?int $sellerId): Invoice
    {
        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $sellerId,
            'document_date' => now(),
            'customer_name' => 'مشتری تست',
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
    }
}
