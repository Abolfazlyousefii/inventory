<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnVariantStateTest extends TestCase
{
    public function test_new_product_modal_uses_selected_models_as_variant_source_of_truth(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('selectedModels:new Set()', $blade);
        $this->assertStringContainsString('const models=useM?[...np.selectedModels]', $blade);
        $this->assertStringContainsString('userTouchedVariants', $blade);
        $this->assertStringContainsString('np.userTouchedVariants=true', $blade);
    }
}
