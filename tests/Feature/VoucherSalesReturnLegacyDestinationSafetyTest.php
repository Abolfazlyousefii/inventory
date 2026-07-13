<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnLegacyDestinationSafetyTest extends TestCase
{
    public function test_edit_form_warns_about_mixed_legacy_draft_destinations_without_auto_update(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('$hasMixedLegacyDestinations', $blade);
        $this->assertStringContainsString('این پیش‌نویس قدیمی دارای چند مقصد انبار است', $blade);
        $this->assertStringContainsString('انتخاب انبار مقصد واحد', $blade);
    }
}
