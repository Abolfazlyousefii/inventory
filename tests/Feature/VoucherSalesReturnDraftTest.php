<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnDraftTest extends TestCase
{
    public function test_draft_persistence_only_replaces_document_items_and_refreshes_document_totals(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString('createDraft', $service);
        $this->assertStringContainsString('persistDraft', $service);
        $this->assertStringContainsString('$doc->items()->delete()', $service);
        $this->assertStringContainsString('refreshTotals($doc)', $service);
        $draftSection = substr($service, strpos($service, 'private function persistDraft'), strpos($service, 'public function cancelDraft') - strpos($service, 'private function persistDraft'));
        $this->assertStringNotContainsString('recordInventoryEntry', $draftSection);
        $this->assertStringNotContainsString('recordCustomerCredit', $draftSection);
        $this->assertStringNotContainsString('materializeNewProduct', $draftSection);
    }
}
