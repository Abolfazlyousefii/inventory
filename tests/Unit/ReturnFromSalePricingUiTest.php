<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReturnFromSalePricingUiTest extends TestCase
{
    public function test_sazeh_price_field_exists_for_mobile_and_desktop_and_shares_state(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/vouchers/return-from-sale/create.blade.php'
        );

        $this->assertStringContainsString("function sazehPriceField(it,i,extraClass='')", $view);
        $this->assertStringContainsString("sazehPriceField(it,i,'mobile-price')", $view);
        $this->assertStringContainsString('inputmode="numeric"', $view);
        $this->assertStringContainsString('document.querySelectorAll(`.price[data-i="${i}"]`)', $view);
        $this->assertStringContainsString('قیمت فروش فعلی نرم‌افزار', $view);
    }

    public function test_internal_price_is_rendered_as_read_only_historical_details(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/vouchers/return-from-sale/create.blade.php'
        );

        $this->assertStringContainsString('function internalPriceDetails(it)', $view);
        $this->assertStringContainsString('واحد فاکتور:', $view);
        $this->assertStringContainsString('سهم تخفیف فاکتور:', $view);
        $this->assertStringNotContainsString('class="form-control form-control-sm price internal', $view);
    }

}
