<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Services\SalesPrintDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SalesPrintDiscountSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_print_summary_uses_items_net_subtotal_and_invoice_discount_only(): void
    {
        $printData = $this->printService()->invoiceData($this->invoice([
            ['quantity' => 20, 'price' => 780_000, 'line_discount_amount' => 500_000],
            ['quantity' => 20, 'price' => 780_000, 'line_discount_amount' => 500_000],
            ['quantity' => 2, 'price' => 6_900_000, 'line_discount_amount' => 0],
        ], 14_000_000, 0), 'customer');

        $this->assertSame(44_000_000, $printData['items']->sum('lineTotal'));
        $this->assertSame(45_000_000, $printData['totals']['subtotal']);
        $this->assertSame(1_000_000, $printData['totals']['itemsDiscount']);
        $this->assertSame(44_000_000, $printData['totals']['itemsNetSubtotal']);
        $this->assertSame(14_000_000, $printData['totals']['invoiceDiscount']);
        $this->assertSame(15_000_000, $printData['totals']['discount']);
        $this->assertSame(0, $printData['totals']['shipping']);
        $this->assertSame(30_000_000, $printData['totals']['total']);
        $this->assertSame($printData['items']->sum('lineTotal'), $printData['totals']['itemsNetSubtotal']);
    }

    public function test_preinvoice_without_product_discount_keeps_gross_and_items_net_subtotal_equal(): void
    {
        $printData = $this->printService()->preinvoiceData($this->preinvoice([
            ['quantity' => 2, 'price' => 10_000_000, 'line_discount_amount' => 0],
            ['quantity' => 1, 'price' => 5_000_000, 'line_discount_amount' => 0],
        ], 3_000_000, 0), 'customer');

        $this->assertSame(25_000_000, $printData['items']->sum('lineTotal'));
        $this->assertSame(0, $printData['totals']['itemsDiscount']);
        $this->assertSame($printData['totals']['subtotal'], $printData['totals']['itemsNetSubtotal']);
        $this->assertSame($printData['items']->sum('lineTotal'), $printData['totals']['itemsNetSubtotal']);
        $this->assertSame(3_000_000, $printData['totals']['invoiceDiscount']);
        $this->assertSame(22_000_000, $printData['totals']['total']);
    }

    public function test_preinvoice_with_only_product_discount_prints_zero_invoice_discount(): void
    {
        $printData = $this->printService()->preinvoiceData($this->preinvoice([
            ['quantity' => 3, 'price' => 10_000_000, 'line_discount_amount' => 2_000_000],
        ], 0, 500_000), 'customer');

        $this->assertSame(28_000_000, $printData['items']->sum('lineTotal'));
        $this->assertSame(28_000_000, $printData['totals']['itemsNetSubtotal']);
        $this->assertSame(0, $printData['totals']['invoiceDiscount']);
        $this->assertSame(2_000_000, $printData['totals']['discount']);
        $this->assertSame(500_000, $printData['totals']['shipping']);
        $this->assertSame(28_500_000, $printData['totals']['total']);
    }

    private function printService(): SalesPrintDocumentService
    {
        return app(SalesPrintDocumentService::class);
    }

    private function invoice(array $items, int $invoiceDiscount, int $shipping): Invoice
    {
        $invoice = new Invoice([
            'uuid' => '00482',
            'customer_name' => 'Test Customer',
            'shipping_price' => $shipping,
            'discount_amount' => $invoiceDiscount + collect($items)->sum('line_discount_amount'),
            'invoice_discount_amount' => $invoiceDiscount,
            'discount_allocation_mode' => 'product_lines',
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        $invoice->setRelation('items', $this->invoiceItems($items));
        $invoice->setRelation('payments', collect());
        $invoice->setRelation('preinvoiceOrder', null);
        $invoice->setRelation('shippingMethod', null);
        $invoice->setRelation('customer', null);

        return $invoice;
    }

    private function preinvoice(array $items, int $invoiceDiscount, int $shipping): PreinvoiceOrder
    {
        $order = new PreinvoiceOrder([
            'uuid' => 'P-00482',
            'customer_name' => 'Test Customer',
            'shipping_price' => $shipping,
            'discount_amount' => $invoiceDiscount + collect($items)->sum('line_discount_amount'),
            'invoice_discount_amount' => $invoiceDiscount,
            'discount_allocation_mode' => 'product_lines',
            'status' => PreinvoiceOrder::STATUS_DRAFT,
        ]);

        $order->setRelation('items', $this->preinvoiceItems($items));
        $order->setRelation('shippingMethod', null);
        $order->setRelation('customer', null);
        $order->setRelation('invoice', null);

        return $order;
    }

    private function invoiceItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index): InvoiceItem {
            $model = new InvoiceItem($item + ['product_id' => $index + 1, 'sort_order' => $index + 1]);
            $model->setRelation('product', null);
            $model->setRelation('variant', null);

            return $model;
        });
    }

    private function preinvoiceItems(array $items): Collection
    {
        return collect($items)->map(function (array $item, int $index): PreinvoiceOrderItem {
            $model = new PreinvoiceOrderItem($item + ['product_id' => $index + 1, 'sort_order' => $index + 1]);
            $model->setRelation('product', null);
            $model->setRelation('variant', null);

            return $model;
        });
    }
}
