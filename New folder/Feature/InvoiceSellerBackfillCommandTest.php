<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSellerBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_eligible_invoice_without_writing(): void
    {
        $seller = $this->seller();
        $invoice = $this->invoice($this->preinvoice($seller->id, null));

        $this->artisan('sales:backfill-invoice-sellers', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNull($invoice->fresh()->seller_id);
        $report = json_decode(file_get_contents(storage_path('logs/invoice-seller-backfill.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame(1, $report['eligible']);
        $this->assertSame(0, $report['backfilled']);
    }

    public function test_apply_copies_only_valid_preinvoice_seller_and_is_idempotent(): void
    {
        $seller = $this->seller();
        $invoice = $this->invoice($this->preinvoice($seller->id, null));

        $this->artisan('sales:backfill-invoice-sellers', ['--apply' => true, '--chunk' => 1])
            ->assertSuccessful();
        $this->assertSame($seller->id, $invoice->fresh()->seller_id);

        $this->artisan('sales:backfill-invoice-sellers', ['--apply' => true, '--chunk' => 1])
            ->assertSuccessful();
        $report = json_decode(file_get_contents(storage_path('logs/invoice-seller-backfill.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $report['eligible']);
        $this->assertSame(0, $report['backfilled']);
    }

    public function test_created_by_is_never_assumed_to_be_the_seller(): void
    {
        $creator = $this->seller();
        $invoice = $this->invoice($this->preinvoice(null, $creator->id));

        $this->artisan('sales:backfill-invoice-sellers', ['--apply' => true])
            ->assertSuccessful();

        $this->assertNull($invoice->fresh()->seller_id);
        $report = json_decode(file_get_contents(storage_path('logs/invoice-seller-backfill.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['without_valid_source']);
    }

    private function seller(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'can_access_erp' => true,
            'is_seller' => true,
        ]);
    }

    private function preinvoice(?int $sellerId, ?int $creatorId): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $creatorId,
            'seller_id' => $sellerId,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        ]);
    }

    private function invoice(PreinvoiceOrder $preinvoice): Invoice
    {
        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $preinvoice->id,
            'seller_id' => null,
            'document_date' => now(),
            'customer_name' => 'مشتری تست',
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
    }
}
