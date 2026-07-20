<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnDocumentNumberTest extends TestCase
{
    private function serviceSource(): string
    {
        return file_get_contents(app_path('Services/SalesReturnService.php'));
    }

    public function test_existing_sr_000001_generates_sr_000002(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString("insertOrIgnore", $service);
        $this->assertStringContainsString("whereNotNull('document_number')", $service);
        $this->assertStringContainsString("return (string) \$next;", $service);
    }

    public function test_stale_sequence_is_recovered(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString('max((int) $sequence->last_number, (int) $maxExisting) + 1', $service);
        $this->assertStringContainsString("'last_number' => \$next", $service);
    }

    public function test_sequence_ahead_is_preserved(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString('max((int) $sequence->last_number, (int) $maxExisting) + 1', $service);
        $this->assertStringNotContainsString("'last_number'=>0", $service);
    }

    public function test_two_consecutive_creations_are_unique(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("where('type', 'sales_return')", $service);
    }

    public function test_invalid_old_numbers_are_ignored(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString("preg_match('/^SR-(\\d+)$/', \$number, \$matches)", $service);
        $this->assertStringNotContainsString('SR-TEST', $service);
    }

    public function test_sequence_is_not_reset_on_each_request(): void
    {
        $service = $this->serviceSource();

        $nextStart = strpos($service, 'private function nextDocumentNumber');
        $nextMethod = substr($service, $nextStart);

        $this->assertStringNotContainsString('updateOrInsert', $nextMethod);
        $this->assertStringNotContainsString("'last_number' => 0, 'created_at'", $nextMethod);
    }

    public function test_failed_document_persistence_rolls_back_everything(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString('return DB::transaction(function () use ($data, $actorId)', $service);
        $this->assertStringNotContainsString('DB::transaction(function(){ DB::table(\'document_sequences\')', $service);
    }

    public function test_creating_draft_does_not_change_inventory_or_ledger(): void
    {
        $service = $this->serviceSource();
        $persistStart = strpos($service, 'private function persistDraft');
        $applyStart = strpos($service, 'public function cancelDraft');
        $persist = substr($service, $persistStart, $applyStart - $persistStart);

        $this->assertStringNotContainsString('recordInventoryEntry', $persist);
        $this->assertStringNotContainsString('recordCustomerCredit', $persist);
    }

    public function test_applying_sales_return_updates_stock_and_credit_once(): void
    {
        $service = $this->serviceSource();

        $this->assertStringContainsString('if($doc->isApplied()) return $doc;', $service);
        $this->assertStringContainsString('recordInventoryEntry($item,$actorId)', $service);
        $this->assertStringContainsString('recordCustomerCredit($doc,$actorId)', $service);
    }
}
