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

    public function test_from_document_does_not_double_count_product_lines_discount(): void
    {
        $doc = (object) [
            'items' => collect([(object) ['quantity' => 1, 'price' => 100_000_000, 'line_discount_amount' => 8_000_000]]),
            'discount_amount' => 8_000_000,
            'invoice_discount_amount' => 0,
            'shipping_price' => 0,
            'discount_allocation_mode' => 'product_lines',
        ];

        $totals = SalesDocumentTotals::fromDocument($doc);

        $this->assertSame(8_000_000, $totals['items_discount']);
        $this->assertSame(0, $totals['invoice_discount']);
        $this->assertSame(8_000_000, $totals['total_discount']);
        $this->assertSame(92_000_000, $totals['grand_total']);
    }

    public function test_from_document_keeps_allocated_lines_legacy_behavior(): void
    {
        $doc = (object) [
            'items' => collect([(object) ['quantity' => 1, 'price' => 100_000_000, 'line_discount_amount' => 8_000_000]]),
            'discount_amount' => 8_000_000,
            'invoice_discount_amount' => 0,
            'shipping_price' => 0,
            'discount_allocation_mode' => 'allocated_lines',
        ];

        $totals = SalesDocumentTotals::fromDocument($doc);

        $this->assertSame(8_000_000, $totals['total_discount']);
        $this->assertSame(92_000_000, $totals['grand_total']);
    }

    public function test_warehouse_quantity_reduction_preserves_effective_unit_discount(): void
    {
        $this->assertSame(2_600_000, SalesDocumentTotals::proportionalLineDiscount(100, 13_000_000, 20, 780_000));
        $this->assertSame(270_000, SalesDocumentTotals::proportionalLineDiscount(10, 900_000, 3, 1_290_000));
    }

    public function test_proportional_discount_uses_deterministic_integer_half_up_rounding(): void
    {
        $this->assertSame(1, SalesDocumentTotals::proportionalLineDiscount(2, 1, 1, 100));
        $this->assertSame(1, SalesDocumentTotals::proportionalLineDiscount(3, 2, 2, 100));
        $this->assertSame(2, SalesDocumentTotals::proportionalLineDiscount(2, 3, 1, 100));
    }

    public function test_proportional_discount_supports_zero_and_increased_quantities_and_caps_at_gross(): void
    {
        $this->assertSame(0, SalesDocumentTotals::proportionalLineDiscount(10, 900_000, 0, 1_290_000));
        $this->assertSame(1_800_000, SalesDocumentTotals::proportionalLineDiscount(10, 900_000, 20, 1_290_000));
        $this->assertSame(300, SalesDocumentTotals::proportionalLineDiscount(1, 1_000, 3, 100));
    }

    public function test_proportional_discount_rejects_an_ambiguous_zero_historical_quantity(): void
    {
        $this->expectException(\DomainException::class);
        SalesDocumentTotals::proportionalLineDiscount(0, 100, 1, 100);
    }

    public function test_invoice_00614_regression_totals_match_the_preserved_commercial_snapshot(): void
    {
        $changedLines = [
            (object) ['quantity' => 20, 'price' => 780_000, 'line_discount_amount' => SalesDocumentTotals::proportionalLineDiscount(100, 13_000_000, 20, 780_000)],
            (object) ['quantity' => 3, 'price' => 1_290_000, 'line_discount_amount' => SalesDocumentTotals::proportionalLineDiscount(10, 900_000, 3, 1_290_000)],
            (object) ['quantity' => 1, 'price' => 1_294_700_000, 'line_discount_amount' => 127_300_000],
        ];

        $totals = SalesDocumentTotals::calculate($changedLines, 0, 0, ['discount_allocation_mode' => 'product_lines']);

        $this->assertSame(1_314_170_000, $totals['subtotal_before_discount']);
        $this->assertSame(130_170_000, $totals['items_discount']);
        $this->assertSame(1_184_000_000, $totals['grand_total']);
    }
}
