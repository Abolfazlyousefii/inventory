<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnNewProductModalTest extends TestCase
{
    public function test_new_product_modal_keeps_product_create_concepts(): void
    {
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        foreach (['npCategoryLevels', 'npCategoryBreadcrumb', 'npModelBrand', 'npModelList', 'npUseDesigns', 'npVariantPreview', 'npProductCodePreview'] as $token) {
            $this->assertStringContainsString($token, $view);
        }
    }
}
