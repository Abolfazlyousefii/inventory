<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCreateRegressionTest extends TestCase
{
    public function test_main_product_create_view_is_not_changed_by_sales_return_inline_modal(): void
    {
        $this->assertFileExists(resource_path('views/products/create.blade.php'));
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('sales_return_inline', file_get_contents(app_path('Services/SalesReturnService.php')));
        $this->assertStringNotContainsString('resources/views/products/create.blade.php', $blade);
    }
}
