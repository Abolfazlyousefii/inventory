<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnCompactFormTest extends TestCase
{
    public function test_create_view_uses_compact_sections_and_bottom_description(): void
    {
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('sr-compact', $view);
        $this->assertStringContainsString('توضیحات سند', $view);
        $this->assertStringContainsString('پس از ذخیره به‌صورت خودکار ایجاد می‌شود', $view);
        $this->assertStringNotContainsString('name="reference_number"', $view);
        $this->assertStringNotContainsString('>بازگشت</a></div></div>', $view);
    }
}
