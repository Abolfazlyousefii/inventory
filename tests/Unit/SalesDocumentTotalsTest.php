<?php

namespace Tests\Unit;

use App\Support\SalesDocumentTotals;
use PHPUnit\Framework\TestCase;

class SalesDocumentTotalsTest extends TestCase
{
    public function test_calculates_without_discount(): void
    {
        $totals = SalesDocumentTotals::calculate([(object) ['quantity' => 2, 'price' => 10_000, 'line_discount_amount' => 0]]);
        $this->assertSame(20_000, $totals['subtotal_before_discount']);
        $this->assertSame(0, $totals['total_discount']);
        $this->assertSame(20_000, $totals['grand_total']);
    }

    public function test_calculates_row_document_and_shipping_discounts(): void
    {
        $items = [
            (object) ['quantity' => 2, 'price' => 10_000, 'line_discount_amount' => 1_000],
            (object) ['quantity' => 1, 'price' => 20_000, 'line_discount_amount' => 2_000],
        ];

        $totals = SalesDocumentTotals::calculate($items, 3_000, 5_000);
        $this->assertSame(40_000, $totals['subtotal_before_discount']);
        $this->assertSame(3_000, $totals['items_discount']);
        $this->assertSame(3_000, $totals['invoice_discount']);
        $this->assertSame(6_000, $totals['total_discount']);
        $this->assertSame(39_000, $totals['grand_total']);
    }

    public function test_allocated_lines_does_not_double_subtract_invoice_discount(): void
    {
        $totals = SalesDocumentTotals::calculate([
            (object) ['quantity' => 1, 'price' => 100_000_000, 'line_discount_amount' => 10_000_000],
        ], 10_000_000, 0, ['discount_allocation_mode' => 'allocated_lines']);

        $this->assertSame(100_000_000, $totals['subtotal_before_discount']);
        $this->assertSame(10_000_000, $totals['items_discount']);
        $this->assertSame(10_000_000, $totals['invoice_discount']);
        $this->assertSame(10_000_000, $totals['total_discount']);
        $this->assertSame(90_000_000, $totals['grand_total']);
    }


    public function test_product_lines_combines_product_and_invoice_discounts(): void
    {
        $totals = SalesDocumentTotals::calculate([
            (object) ['quantity' => 1, 'price' => 22_000_000, 'line_discount_amount' => 2_200_000],
        ], 990_000, 0, ['discount_allocation_mode' => 'product_lines']);

        $this->assertSame(22_000_000, $totals['subtotal_before_discount']);
        $this->assertSame(2_200_000, $totals['items_discount']);
        $this->assertSame(990_000, $totals['invoice_discount']);
        $this->assertSame(3_190_000, $totals['total_discount']);
        $this->assertSame(18_810_000, $totals['grand_total']);
    }

    public function test_discounts_are_capped_at_subtotal(): void
    {
        $totals = SalesDocumentTotals::calculate([
            (object) ['quantity' => 1, 'price' => 10_000, 'line_discount_amount' => 20_000],
        ], 20_000);

        $this->assertSame(10_000, $totals['items_discount']);
        $this->assertSame(10_000, $totals['total_discount']);
        $this->assertSame(0, $totals['grand_total']);
    }
}
