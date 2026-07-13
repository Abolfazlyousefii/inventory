<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnNewProductPriceTest extends TestCase
{
    public function test_new_product_prices_use_sanitized_integer_state_instead_of_formatted_input_values(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('sanitizeMoneyInput', $blade);
        $this->assertStringContainsString('moneyToInteger', $blade);
        $this->assertStringContainsString('state.newProduct.prices', $blade);
        $this->assertStringContainsString('function npSyncPrice(id)', $blade);
        $this->assertStringContainsString("purchase_price:npPrice('npPurchase')??0", $blade);
        $this->assertStringNotContainsString('NaN', $blade);
    }
}
