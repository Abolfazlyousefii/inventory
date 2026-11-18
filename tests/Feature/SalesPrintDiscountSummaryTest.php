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

    public function test_customer_groups_variants_without_repeating_allocated_discounts_or_losing_sources(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 3, 'price' => 100000, 'line_discount_amount' => 3000],
            ['product_id' => 7, 'source_warehouse_id' => 6, 'quantity' => 2, 'price' => 100000, 'line_discount_amount' => 2000],
            ['product_id' => 7, 'quantity' => 1, 'price' => 120000, 'line_discount_amount' => 0],
            ['product_id' => 8, 'quantity' => 1, 'price' => 100000, 'line_discount_amount' => 0],
        ], 10000, 0);
        $data = $this->printService()->invoiceData($invoice, 'customer');
        $this->assertCount(3, $data['customerGroups']);
        $group = $data['customerGroups']->first();
        $this->assertSame(5, $group['quantity']);
        $this->assertSame(5000, $group['discount']);
        $this->assertSame(495000, $group['total']);
        $this->assertSame([null, 6], $group['items']->pluck('sourceWarehouseId')->all());
        $this->assertSame(715000, $data['customerGroups']->sum('total'));
        $this->assertSame(705000, $data['totals']['total']);
        $html = view('prints.invoice', ['printData' => $data])->render();
        $this->assertSame(1, substr_count($html, 'تخفیف این کالا'));
        $this->assertStringContainsString('تخفیف کلی فاکتور', $html);
        $this->assertStringContainsString('شوروم', $html);
        $this->assertStringNotContainsString('انبار مرکزی', $html);
        $warehouse = $this->printService()->invoiceData($invoice, 'warehouse');
        $this->assertCount(4, $warehouse['items']);
        $warehouseHtml = view('prints.invoice', ['printData' => $warehouse])->render();
        $this->assertStringNotContainsString('تخفیف این کالا', $warehouseHtml);
        $this->assertStringNotContainsString('انبار مرکزی', $warehouseHtml);
        $this->assertStringContainsString('شوروم', $warehouseHtml);
        $this->assertStringContainsString('کنترل جمع‌آوری', $warehouseHtml);
        $this->assertStringNotContainsString('قیمت واحد', $warehouseHtml);
    }

    public function test_many_models_show_one_product_price_and_escape_variant_names_without_zero_discount_noise(): void
    {
        $invoice = $this->invoice(array_fill(0, 25, [
            'product_id' => 7, 'quantity' => 2, 'price' => 100000, 'line_discount_amount' => 0,
        ]), 0, 0);
        foreach ($invoice->items as $index => $item) {
            $item->setRelation('product', new \App\Models\Product(['name' => 'گارد سیلیکون']));
            $variant = new \App\Models\ProductVariant(['variant_name' => 'گارد سیلیکون مدل '.$index.' <script>', 'variety_name' => '—']);
            $variant->setRelation('modelList', new \App\Models\ProductVariant(['model_name' => 'مدل '.$index]));
            $variant->setRelation('color', null);
            $item->setRelation('variant', $variant);
        }
        $data = $this->printService()->invoiceData($invoice, 'customer');
        $this->assertCount(1, $data['customerGroups']);
        $this->assertSame(50, $data['customerGroups'][0]['quantity']);
        $this->assertCount(25, $data['customerGroups'][0]['items']);
        $html = view('prints.invoice', ['printData' => $data])->render();
        $this->assertSame(1, substr_count($html, 'گارد سیلیکون'));
        $this->assertStringContainsString('مدل ۲۴ &lt;script&gt;', $html);
        $this->assertStringContainsString('model-divider', $html);
        $this->assertStringContainsString('print-money', $html);
        $this->assertStringContainsString('۵٬۰۰۰٬۰۰۰', $html);
        $this->assertStringContainsString('header-customer', $html);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $rowNumbers = [];
        foreach ($xpath->query('//span[@class="model-row-number"]') as $node) {
            $rowNumbers[] = (int) strtr($node->textContent, array_combine(
                preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789')
            ));
        }
        $this->assertSame([1, 7, 2, 8, 3, 9, 4, 10, 5, 11, 6, 12], array_slice($rowNumbers, 0, 12));
        sort($rowNumbers);
        $this->assertSame(range(1, 25), $rowNumbers);
        $visibleText = '';
        foreach ($xpath->query('//main//text()') as $node) $visibleText .= $node->textContent;
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $visibleText);
        $this->assertStringNotContainsString('تخفیف این کالا', $html);
        $this->assertSame(5000000, $data['totals']['total']);
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
