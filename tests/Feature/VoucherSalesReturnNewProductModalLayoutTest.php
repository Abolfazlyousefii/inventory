<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnNewProductModalLayoutTest extends TestCase
{
    public function test_new_product_modal_uses_grid_content_and_static_footer_layout(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('grid-template-rows:auto auto minmax(0,1fr) auto', $blade);
        $this->assertStringContainsString('.np-modal__body{min-height:0;overflow-y:auto;overflow-x:hidden', $blade);
        $this->assertStringContainsString('.np-modal .modal-header,.np-modal .modal-footer{position:static', $blade);
        $this->assertStringContainsString('پس از انتخاب دسته‌بندی، کد کالا نمایش داده می‌شود.', $blade);
    }
}
