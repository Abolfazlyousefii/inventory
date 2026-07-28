<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductVariant;
use App\Services\SalesReturnCalculationService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SalesReturnCalculationServiceTest extends TestCase
{
    private SalesReturnCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SalesReturnCalculationService;
    }

    public function test_internal_refund_uses_invoice_snapshot_instead_of_current_variant_price(): void
    {
        $item = $this->item(1, 1, 20_000_000);
        $variant = new ProductVariant;
        $variant->forceFill(['sell_price' => 30_000_000]);
        $item->setRelation('variant', $variant);

        $breakdown = $this->calculator->invoiceItemBreakdowns(
            $this->invoice([$item], 'product_lines')
        )[1];

        $this->assertSame(20_000_000, $breakdown['historical_unit_price']);
        $this->assertSame(20_000_000, $breakdown['net_refund_unit_price']);
    }

    public function test_line_discount_is_spread_over_the_historical_row(): void
    {
        $item = $this->item(1, 2, 20_000_000, 4_000_000);

        $breakdown = $this->calculator->invoiceItemBreakdowns(
            $this->invoice([$item], 'product_lines')
        )[1];

        $this->assertSame(40_000_000, $breakdown['gross_amount']);
        $this->assertSame(4_000_000, $breakdown['line_discount_total']);
        $this->assertSame(18_000_000, $breakdown['net_refund_unit_price']);
    }

    public function test_product_lines_applies_line_and_invoice_discounts_once(): void
    {
        $item = $this->item(1, 1, 22_000_000, 2_200_000);
        $invoice = $this->invoice([$item], 'product_lines', 990_000, 3_190_000);

        $breakdown = $this->calculator->invoiceItemBreakdowns($invoice)[1];

        $this->assertSame(2_200_000, $breakdown['line_discount_total']);
        $this->assertSame(990_000, $breakdown['allocated_invoice_discount_total']);
        $this->assertSame(18_810_000, $breakdown['net_refund_total']);
    }

    public function test_allocated_lines_does_not_subtract_invoice_discount_twice(): void
    {
        $item = $this->item(1, 1, 100_000_000, 10_000_000);
        $invoice = $this->invoice([$item], 'allocated_lines', 10_000_000, 10_000_000);

        $breakdown = $this->calculator->invoiceItemBreakdowns($invoice)[1];

        $this->assertSame(0, $breakdown['allocated_invoice_discount_total']);
        $this->assertSame(90_000_000, $breakdown['net_refund_total']);
    }

    public function test_legacy_invoice_falls_back_to_total_minus_line_discount(): void
    {
        $item = $this->item(1, 1, 100_000, 10_000);
        $invoice = $this->invoice([$item], null, 0, 15_000);

        $breakdown = $this->calculator->invoiceItemBreakdowns($invoice)[1];

        $this->assertSame(5_000, $breakdown['allocated_invoice_discount_total']);
        $this->assertSame(85_000, $breakdown['net_refund_total']);
    }

    public function test_invoice_discount_floor_and_remainder_allocation_is_stable(): void
    {
        $first = $this->item(10, 1, 100);
        $second = $this->item(20, 1, 100);
        $invoice = $this->invoice([$second, $first], 'product_lines', 1, 1);

        $this->assertSame([10 => 1, 20 => 0], $this->calculator->allocateInvoiceDiscount($invoice));
    }

    public function test_last_partial_return_receives_the_exact_remaining_rial(): void
    {
        $net = 100;
        $firstReturn = $this->calculator->cumulativeRefundAmount($net, 3, 1);
        $allReturns = $this->calculator->cumulativeRefundAmount($net, 3, 3);
        $lastReturn = $allReturns - $firstReturn;

        $this->assertSame(33, $firstReturn);
        $this->assertSame(67, $lastReturn);
        $this->assertSame($net, $firstReturn + $lastReturn);
    }

    private function item(int $id, int $quantity, int $price, int $lineDiscount = 0): InvoiceItem
    {
        $item = new InvoiceItem;
        $item->forceFill([
            'id' => $id,
            'quantity' => $quantity,
            'price' => $price,
            'line_discount_amount' => $lineDiscount,
        ]);

        return $item;
    }

    private function invoice(
        array $items,
        ?string $mode,
        int $invoiceDiscount = 0,
        int $totalDiscount = 0
    ): Invoice {
        $invoice = new Invoice;
        $invoice->forceFill([
            'discount_allocation_mode' => $mode,
            'invoice_discount_amount' => $invoiceDiscount,
            'discount_amount' => $totalDiscount,
        ]);
        $invoice->setRelation('items', new Collection($items));

        return $invoice;
    }
}
