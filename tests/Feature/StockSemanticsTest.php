<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use Tests\TestCase;

class StockSemanticsTest extends TestCase
{
    public function test_variant_available_stock_returns_stock_without_subtracting_reserved(): void
    {
        $variant = new ProductVariant(['stock' => 5, 'reserved' => 3]);
        $this->assertSame(5, $variant->available_stock);
    }
}
