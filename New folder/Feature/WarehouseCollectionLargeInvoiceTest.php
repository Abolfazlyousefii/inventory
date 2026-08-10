<?php

namespace Tests\Feature;

use Tests\TestCase;

class WarehouseCollectionLargeInvoiceTest extends TestCase
{
    public function test_collection_form_serializes_large_item_tables_to_single_json_payload(): void
    {
        $blade = file_get_contents(base_path('resources/views/vouchers/sales/edit.blade.php'));

        $this->assertStringContainsString('name="items_payload" id="itemsPayload"', $blade);
        $this->assertStringContainsString('name="items_payload_count" id="itemsPayloadCount"', $blade);
        $this->assertStringContainsString('function buildItemsPayload()', $blade);
        $this->assertStringContainsString('itemsPayload', $blade);
        $this->assertStringContainsString('JSON.stringify(items)', $blade);
        $this->assertStringContainsString('input[name^="items["]', $blade);
        $this->assertStringContainsString('input.disabled = true', $blade);
    }

    public function test_controller_decodes_and_validates_large_json_payload_before_service_call(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/InvoiceController.php'));

        $this->assertStringContainsString('items_payload', $controller);
        $this->assertStringContainsString('items_payload_count', $controller);
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $controller);
        $this->assertStringContainsString('اطلاعات اقلام فاکتور ناقص یا نامعتبر است. صفحه را تازه‌سازی کرده و دوباره تلاش کنید.', $controller);
        $this->assertStringContainsString('اطلاعات فرم به‌صورت ناقص به سرور رسیده است. هیچ تغییری ثبت نشد.', $controller);
        $this->assertStringContainsString('count($items) !== $expectedCount', $controller);
        $this->assertStringContainsString("'items.*.quantity' => 'required|integer|min:0'", $controller);
        $this->assertStringContainsString('filter_var($item[\'_delete\'] ?? false, FILTER_VALIDATE_BOOLEAN)', $controller);
        $this->assertStringContainsString('$item[\'quantity\'] = 0', $controller);
        $this->assertStringContainsString('updateCollectedItems($invoice, $items', $controller);
    }

    public function test_large_invoice_input_count_exceeds_default_php_limit_without_json(): void
    {
        $rows = 180;
        $inputsPerRow = 7;
        $nonItemInputs = 7; // _token, _method, opened_at, reason/note, payload, payload_count.

        $legacyInputVariables = ($rows * $inputsPerRow) + $nonItemInputs;
        $jsonInputVariables = $nonItemInputs;

        $this->assertGreaterThan(1000, $legacyInputVariables);
        $this->assertLessThan(1000, $jsonInputVariables);
        $this->assertSame(1267, $legacyInputVariables);
    }
}
