<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnDraftCompatibilityTest extends TestCase
{
    public function test_normalizer_preserves_legacy_payload_fields_as_schema_two(): void
    {
        $normalizer = file_get_contents(app_path('Services/SalesReturnNewProductPayloadNormalizer.php'));

        $this->assertStringContainsString('schema_version', $normalizer);
        $this->assertStringContainsString('product_name', $normalizer);
        $this->assertStringContainsString('model_list_id', $normalizer);
        $this->assertStringContainsString('selected_variants', $normalizer);
    }
}
