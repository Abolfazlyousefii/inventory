<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceEffectiveSellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_show_and_finance_report_use_transferred_seller_without_changing_creator(): void
    {
        $creator = $this->seller('Original creator');
        $seller = $this->seller('Effective seller');
        $order = $this->preinvoice($creator, $seller);
        $invoice = $this->invoice($order, $seller);
        $createdByBefore = $order->created_by;

        $this->actingAs($this->owner())
            ->get(route('invoices.show', $invoice->uuid))
            ->assertOk()
            ->assertSee('Effective seller')
            ->assertDontSee('Original creator');

        $sellerReport = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]));
        $this->assertSame(1, collect($sellerReport->viewData('rows'))->sole()['invoice_count']);

        $creatorReport = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $creator->id,
        ]));
        $this->assertTrue(collect($creatorReport->viewData('rows'))->isEmpty());
        $this->assertSame($createdByBefore, $order->fresh()->created_by);
    }

    public function test_effective_seller_falls_back_to_preinvoice_seller_then_legacy_creator(): void
    {
        $creator = $this->seller('Legacy creator');
        $preinvoiceSeller = $this->seller('Preinvoice seller');
        $order = $this->preinvoice($creator, $preinvoiceSeller);

        $fromPreinvoice = $this->invoice($order, null);
        $this->assertSame($preinvoiceSeller->id, $fromPreinvoice->effective_seller_id);
        $this->assertTrue($fromPreinvoice->effectiveSeller()->is($preinvoiceSeller));

        $order->update(['seller_id' => null]);
        $legacyOrder = $this->preinvoice($creator, $preinvoiceSeller);
        $legacyOrder->update(['seller_id' => null]);
        $legacy = $this->invoice($legacyOrder->fresh(), null);
        $this->assertSame($creator->id, $legacy->effective_seller_id);
        $this->assertTrue($legacy->effectiveSeller()->is($creator));
    }

    public function test_commission_document_uses_effective_seller_and_effective_invoice_date(): void
    {
        $creator = $this->seller('Original creator');
        $seller = $this->seller('Transferred seller');
        $order = $this->preinvoice($creator, $seller);
        $invoice = $this->invoice($order, $seller);
        $actor = $this->owner();

        $document = app(SellerCommissionDocumentService::class)->createDocument([
            'user_id' => $seller->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'invoice_ids' => [$invoice->id],
        ], $actor);

        $this->assertSame($seller->id, $document->seller_id);
        $this->assertDatabaseHas('seller_sales_document_items', [
            'invoice_id' => $invoice->id,
            'invoice_date_snapshot' => '2026-07-15 10:00:00',
        ]);
        $this->assertSame($creator->id, $order->fresh()->created_by);
    }

    public function test_finance_commission_batch_and_export_snapshot_the_effective_seller(): void
    {
        $creator = $this->seller('Old seller');
        $seller = $this->seller('New seller');
        $order = $this->preinvoice($creator, $seller);
        $invoice = $this->invoice($order, $seller);
        $actor = $this->owner();

        $response = $this->actingAs($actor)->post(route('finance.reports.sales-visitors.commission-batches.store'), [
            'visitor_id' => $seller->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'invoice_ids' => [$invoice->id],
        ]);
        $batchId = (int) \App\Models\FinanceCommissionBatch::query()->value('id');
        $response->assertRedirect(route('finance.reports.sales-visitors.commission-batches.show', $batchId));

        $this->assertDatabaseHas('finance_commission_batches', ['id' => $batchId, 'visitor_id' => $seller->id]);
        $this->assertDatabaseHas('finance_commission_batch_items', [
            'batch_id' => $batchId,
            'invoice_id' => $invoice->id,
            'invoice_date' => '2026-07-15 10:00:00',
        ]);
        $export = $this->actingAs($actor)
            ->get(route('finance.reports.sales-visitors.commission-batches.export', $batchId));
        $export->assertOk();
        $this->assertStringContainsString('New seller', $export->streamedContent());
        $this->assertSame($creator->id, $order->fresh()->created_by);
    }

    public function test_null_document_date_falls_back_to_invoice_created_at_in_report_and_commission_query(): void
    {
        $seller = $this->seller('Dated seller');
        $order = $this->preinvoice($seller, $seller);
        $invoice = $this->invoice($order, $seller);
        $invoice->forceFill([
            'document_date' => null,
            'created_at' => '2026-07-22 11:30:00',
            'updated_at' => '2026-07-22 11:30:00',
        ])->saveQuietly();

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-22',
        ]));
        $this->assertSame(1, collect($response->viewData('rows'))->sole()['invoice_count']);

        $ids = app(SellerCommissionDocumentService::class)
            ->getAvailableInvoices($seller->id, '2026-07-22', '2026-07-22')
            ->pluck('invoices.id');
        $this->assertTrue($ids->contains($invoice->id));
        $this->assertSame('2026-07-22 11:30:00', app(SellerCommissionDocumentService::class)
            ->resolveInvoiceInitialDate($invoice->fresh())->format('Y-m-d H:i:s'));
    }

    private function seller(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'can_access_erp' => true,
            'is_seller' => true,
        ]);
    }

    private function preinvoice(User $creator, User $seller): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $creator->id,
            'seller_id' => $seller->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'Test customer',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'Tehran',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);
    }

    private function invoice(PreinvoiceOrder $order, ?User $seller): Invoice
    {
        static $number = 600;

        return Invoice::query()->create([
            'uuid' => str_pad((string) ++$number, 5, '0', STR_PAD_LEFT),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $seller?->id,
            'document_date' => '2026-07-15 10:00:00',
            'customer_name' => 'Test customer',
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
