<?php

namespace Tests\Feature;

use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocumentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentBackfillCommandTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_default_dry_run_reports_mismatch_without_mutating_it(): void
    {
        [$item] = $this->historicalMismatch(false);

        $this->artisan('sales:audit-commission-seller-mismatches')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('total mismatches: 1')
            ->expectsOutputToContain('audit missing: 1')
            ->assertSuccessful();

        $this->assertSame(SellerSalesDocumentItem::STATUS_ACTIVE, $item->fresh()->status);
        $this->assertNotNull($item->fresh()->active_invoice_id);
    }

    public function test_apply_uses_matching_audit_recalculates_and_is_idempotent(): void
    {
        [$item, $document, $sellerB, $audit] = $this->historicalMismatch(true);

        $this->artisan('sales:audit-commission-seller-mismatches', ['--apply' => true])
            ->expectsOutputToContain('total mismatches: 1')
            ->assertSuccessful();

        $item->refresh();
        $this->assertSame(SellerSalesDocumentItem::STATUS_REASSIGNED, $item->status);
        $this->assertNull($item->active_invoice_id);
        $this->assertSame($sellerB->id, $item->reassigned_to_seller_id);
        $this->assertSame($audit->id, $item->reassignment_audit_id);
        $this->assertSame($audit->changed_at->format('Y-m-d H:i:s'), $item->reassigned_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $document->fresh()->invoice_count);
        $this->assertSame(0, $document->fresh()->total_sales_amount);

        $this->artisan('sales:audit-commission-seller-mismatches', ['--apply' => true])
            ->expectsOutputToContain('total mismatches: 0')
            ->assertSuccessful();
        $this->assertSame($audit->id, $item->fresh()->reassignment_audit_id);
    }

    public function test_apply_without_audit_keeps_reassignment_time_nullable(): void
    {
        [$item] = $this->historicalMismatch(false);

        $this->artisan('sales:audit-commission-seller-mismatches', ['--apply' => true])->assertSuccessful();

        $this->assertSame(SellerSalesDocumentItem::STATUS_REASSIGNED, $item->fresh()->status);
        $this->assertNull($item->fresh()->reassignment_audit_id);
        $this->assertNull($item->fresh()->reassigned_at);
    }

    private function historicalMismatch(bool $withAudit): array
    {
        $actor = $this->financeActor();
        $sellerA = $this->erpUser(['is_seller' => true, 'name' => 'Seller A']);
        $sellerB = $this->erpUser(['is_seller' => true, 'name' => 'Seller B']);
        $invoice = $this->makeInvoice($sellerA, 5000, '2026-07-10', ['seller_id' => $sellerA->id]);
        $invoice->preinvoiceOrder->update(['seller_id' => $sellerA->id]);
        $document = $this->createCommissionDocument($sellerA, [$invoice], $actor);
        $item = $document->items()->firstOrFail();
        $invoice->forceFill(['seller_id' => $sellerB->id])->save();
        $invoice->preinvoiceOrder->forceFill(['seller_id' => $sellerB->id])->save();
        $audit = null;

        if ($withAudit) {
            $audit = SellerReassignmentAudit::query()->create([
                'invoice_id' => $invoice->id,
                'preinvoice_id' => $invoice->preinvoice_order_id,
                'old_seller_id' => $sellerA->id,
                'new_seller_id' => $sellerB->id,
                'changed_by' => $actor->id,
                'source' => 'bulk',
                'reason' => 'historical transfer',
                'changed_at' => now()->addMinute(),
            ]);
        }

        return [$item, $document, $sellerB, $audit];
    }
}
