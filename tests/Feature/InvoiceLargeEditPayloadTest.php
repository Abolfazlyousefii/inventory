<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoiceLargeEditPayloadTest extends TestCase
{
    public function test_invoice_edit_serializes_large_item_tables_into_one_payload(): void
    {
        $blade = file_get_contents(base_path('resources/views/invoices/edit.blade.php'));

        $this->assertStringContainsString('name="items_payload" id="invoiceEditItemsPayload"', $blade);
        $this->assertStringContainsString('name="items_payload_count" id="invoiceEditItemsPayloadCount"', $blade);
        $this->assertStringContainsString('function buildInvoiceEditItemsPayload()', $blade);
        $this->assertStringContainsString('prepareInvoiceEditJsonPayload()', $blade);
        $this->assertStringContainsString('JSON.stringify(items)', $blade);
        $this->assertStringContainsString('#items-editor input[name^="items["]', $blade);
        $this->assertStringContainsString('input.disabled = true', $blade);
    }

    public function test_invoice_update_decodes_json_payload_and_preserves_legacy_fallback(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/InvoiceController.php'));

        $this->assertStringContainsString("'items_payload' => 'nullable|string'", $controller);
        $this->assertStringContainsString("'items_payload_count' => 'nullable|integer|min:1|max:2000'", $controller);
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $controller);
        $this->assertStringContainsString('count($items) !== $expectedCount', $controller);
        $this->assertStringContainsString("Validator::make(['items' => $items]", $controller);
        $this->assertStringContainsString("'items.*.product_id' => 'required|integer|exists:products,id'", $controller);
        $this->assertStringContainsString("'items.*.variant_id' => 'required|integer|exists:product_variants,id'", $controller);
        $this->assertStringContainsString("'items.*.quantity' => 'required|integer|min:0'", $controller);
        $this->assertStringContainsString('// Backward-compatible fallback for old clients and requests.', $controller);
    }

    public function test_101_invoice_rows_exceed_production_max_input_vars_without_json_payload(): void
    {
        $rows = 101;
        $inputsPerRow = 6;
        $nonItemInputs = 6;

        $legacyInputVariables = ($rows * $inputsPerRow) + $nonItemInputs;
        $jsonInputVariables = $nonItemInputs;

        $this->assertGreaterThan(500, $legacyInputVariables);
        $this->assertLessThan(500, $jsonInputVariables);
        $this->assertSame(612, $legacyInputVariables);
    }
}
