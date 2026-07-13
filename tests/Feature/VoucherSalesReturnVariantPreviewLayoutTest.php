<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnVariantPreviewLayoutTest extends TestCase
{
    public function test_new_product_variant_preview_is_full_width_vertical_scroll_grid(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('.sr-variant-preview{grid-column:1/-1;width:100%;max-width:none;min-width:0}', $blade);
        $this->assertStringContainsString('overflow-y:auto;overflow-x:hidden', $blade);
        $this->assertStringContainsString('.sr-variant-preview__header{position:sticky;top:0;z-index:2', $blade);
        $this->assertStringContainsString('sr-variant-preview__row', $blade);
        $this->assertStringContainsString('data-label="نام تنوع"', $blade);
        $this->assertStringContainsString('افزودن \'+toPersianDigits(picked.length)+\' تنوع به سند', $blade);
    }
}
