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
        $this->assertCount(2, $data['customerGroups']);
        $group = $data['customerGroups']->first();
        $this->assertSame(6, $group['quantity']);
        $this->assertSame(5000, $group['discount']);
        $this->assertSame(615000, $group['total']);
        $this->assertSame([null, 6, null], $group['items']->pluck('sourceWarehouseId')->all());
        $this->assertSame(715000, $data['customerGroups']->sum('total'));
        $this->assertSame(705000, $data['totals']['total']);
        $html = view('prints.invoice', ['printData' => $data])->render();
        $this->assertSame(2, substr_count($html, 'class="product-heading"'));
        $this->assertStringContainsString('تخفیف واحد', $html);
        $this->assertStringContainsString('۲۹۷٬۰۰۰', $html);
        $this->assertStringContainsString('۱۹۸٬۰۰۰', $html);
        $this->assertStringContainsString('۱۲۰٬۰۰۰', $html);
        $this->assertStringNotContainsString('تخفیف این کالا', $html);
        $this->assertStringContainsString('تخفیف کلی فاکتور', $html);
        $this->assertStringContainsString('شوروم', $html);
        $this->assertStringNotContainsString('انبار مرکزی', $html);
        $this->assertStringNotContainsString('model-schedule', $html);
        $warehouse = $this->printService()->invoiceData($invoice, 'warehouse');
        $this->assertCount(4, $warehouse['items']);
        $warehouseHtml = view('prints.invoice', ['printData' => $warehouse])->render();
        $this->assertSame(2, substr_count($warehouseHtml, 'class="product-heading"'));
        $this->assertStringNotContainsString('تخفیف این کالا', $warehouseHtml);
        $this->assertStringContainsString('تخفیف کل', $warehouseHtml);
        $this->assertStringNotContainsString('انبار مرکزی', $warehouseHtml);
        $this->assertStringContainsString('شوروم', $warehouseHtml);
        $this->assertStringContainsString('کنترل جمع‌آوری', $warehouseHtml);
        $this->assertStringContainsString('قیمت واحد', $warehouseHtml);
        $this->assertStringContainsString('مبلغ نهایی', $warehouseHtml);
    }

    public function test_invoice_header_compacts_customer_and_document_details_into_a_shared_frame(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 3, 'price' => 100_000, 'line_discount_amount' => 3_000],
        ], 0, 0);
        $invoice->customer_name = 'مشتری نمونه';
        $invoice->customer_mobile = '09120000000';
        $invoice->seller_name = 'فروشنده نمونه';

        $service = $this->printService();

        foreach (['customer', 'warehouse'] as $mode) {
            $html = view('prints.invoice', [
                'printData' => $service->invoiceData($invoice, $mode),
            ])->render();

            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);

            $header = $xpath->query('//header[contains(concat(" ", normalize-space(@class), " "), " invoice-header ")]')->item(0);
            $this->assertNotNull($header);
            $this->assertSame(1, $xpath->query('./div[contains(@class, "header-top")]', $header)->length);
            $this->assertSame(1, $xpath->query('./div[contains(@class, "header-details")]', $header)->length);
            $this->assertSame(1, $xpath->query('./div[contains(@class, "header-top")]//h1[contains(@class, "doc-title")]', $header)->length);
            $this->assertSame(2, $xpath->query('./div[contains(@class, "header-details")]/section[contains(@class, "header-info")]', $header)->length);
            $this->assertSame(1, $xpath->query('.//section[contains(@class, "header-customer-info")]', $header)->length);
            $this->assertSame(1, $xpath->query('.//section[contains(@class, "header-document")]', $header)->length);
            $this->assertSame(0, $xpath->query('.//*[contains(@class, "header-panel")]', $header)->length);
            $this->assertStringContainsString('شماره فاکتور', $html);
            $this->assertStringContainsString('تاریخ سند', $html);
            $this->assertStringContainsString('نام مشتری', $html);
            $this->assertStringContainsString('شماره تماس', $html);
            $this->assertStringContainsString('مشتری نمونه', $html);
            $this->assertStringContainsString('۰۹۱۲۰۰۰۰۰۰۰', $html);
            $this->assertStringContainsString('doc-meta-row', $html);
            $this->assertStringContainsString('header-customer', $html);
            $this->assertStringContainsString('تخفیف واحد', $html);
            $this->assertStringContainsString('مبلغ نهایی', $html);
        }
    }

    public function test_compact_header_keeps_long_customer_address_without_fixed_height(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 1, 'price' => 100_000, 'line_discount_amount' => 0],
        ], 0, 0);
        $data = $this->printService()->invoiceData($invoice, 'customer');
        $data['customer']['address'] = str_repeat('خیابان نمونه ساختمان تجاری ', 10);
        $html = view('prints.invoice', ['printData' => $data])->render();

        $this->assertStringContainsString($data['customer']['address'], $html);
        $this->assertMatchesRegularExpression('/\\.invoice-header\\s*\\{[^}]*display:block/s', $html);
        $this->assertMatchesRegularExpression('/\\.header-details\\s*\\{[^}]*grid-template-columns:repeat\\(2,minmax\\(0,1fr\\)\\)/s', $html);
        $this->assertMatchesRegularExpression('/\\.header-customer \\.info-row > :last-child\\s*\\{[^}]*overflow-wrap:anywhere/s', $html);
    }

    public function test_warehouse_preinvoice_uses_same_compact_header(): void
    {
        $order = $this->preinvoice([
            ['product_id' => 7, 'quantity' => 1, 'price' => 100_000, 'line_discount_amount' => 0],
        ], 0, 0);
        $html = view('prints.invoice', [
            'printData' => $this->printService()->preinvoiceData($order, 'warehouse'),
        ])->render();

        $this->assertStringContainsString('پیش‌فاکتور فروش', $html);
        $this->assertStringContainsString('شماره پیش‌فاکتور', $html);
        $this->assertStringContainsString('مشخصات مشتری', $html);
        $this->assertStringContainsString('اطلاعات سند', $html);
        $this->assertStringContainsString('class="header-details"', $html);
    }

    public function test_print_typography_keeps_counts_and_prices_legible_on_a4(): void
    {
        $html = view('prints.invoice', [
            'printData' => $this->printService()->invoiceData($this->invoice([
                ['product_id' => 7, 'quantity' => 3, 'price' => 100_000, 'line_discount_amount' => 0],
            ], 0, 0), 'customer'),
        ])->render();

        $this->assertMatchesRegularExpression('/\\.customer-items \\.variant-row td\\s*\\{[^}]*font-size:\\s*11px/s', $html);
        $this->assertMatchesRegularExpression('/\\.product-summary\\s*\\{[^}]*font-size:\\s*10px;[^}]*font-weight:\\s*700/s', $html);
        $this->assertMatchesRegularExpression('/\\.customer-items \\.variant-row td\\.col-discount-unit,[^}]*font-size:\\s*10\\.5px/s', $html);
        $this->assertMatchesRegularExpression('/\\.customer-items tfoot td\\s*\\{[^}]*font-size:\\s*11px/s', $html);
        $this->assertStringContainsString('class="product-summary"', $html);
    }

    public function test_unit_and_total_discounts_render_separately_for_all_invoice_copies(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 4, 'price' => 100_000, 'line_discount_amount' => 8_000],
            ['product_id' => 7, 'quantity' => 3, 'price' => 100_000, 'line_discount_amount' => 5_000],
            ['product_id' => 8, 'quantity' => 1, 'price' => 50_000, 'line_discount_amount' => 0],
        ], 2_000, 0);
        $service = $this->printService();
        $data = $service->invoiceData($invoice, 'customer');

        $this->assertSame(2_000, $data['items'][0]['unitDiscount']);
        $this->assertFalse($data['items'][0]['unitDiscountApproximate']);
        $this->assertSame(1_667, $data['items'][1]['unitDiscount']);
        $this->assertTrue($data['items'][1]['unitDiscountApproximate']);
        $this->assertSame(0, $data['items'][2]['unitDiscount']);
        $this->assertFalse($data['items'][2]['unitDiscountApproximate']);
        $this->assertSame(13_000, $data['totals']['itemsDiscount']);
        $this->assertSame(13_000, $data['customerGroups']->sum('discount'));

        foreach (['customer', 'warehouse'] as $mode) {
            $html = view('prints.invoice', [
                'printData' => $service->invoiceData($invoice, $mode),
            ])->render();
            $this->assertStringContainsString('تخفیف واحد', $html);
            $this->assertStringContainsString('تخفیف کل', $html);
            $this->assertStringNotContainsString('تخفیف ردیف', $html);
            $this->assertStringContainsString('مبلغ نهایی ردیف', $html);
            $this->assertStringContainsString($mode === 'customer' ? 'قیمت تمام‌شده واحد = قیمت واحد − تخفیف واحد' : 'تعداد × قیمت واحد − تخفیف کل', $html);
            $this->assertStringContainsString('تقریبی', $html);
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $rows = $xpath->query('//tr[contains(concat(" ", normalize-space(@class), " "), " variant-row ")]');
            $this->assertSame(3, $rows->length);
            $first = $rows->item(0);
            $second = $rows->item(1);
            $this->assertStringContainsString('۲٬۰۰۰', $xpath->evaluate('string(.//td[contains(@class, "col-discount-unit")])', $first));
            $this->assertStringContainsString($mode === 'customer' ? '۹۸٬۰۰۰' : '۸٬۰۰۰', $xpath->evaluate('string(.//td[contains(@class, "col-discount-total")])', $first));
            $this->assertStringContainsString('۳۹۲٬۰۰۰', $xpath->evaluate('string(.//td[contains(@class, "col-total")])', $first));
            $this->assertStringContainsString('۱٬۶۶۷', $xpath->evaluate('string(.//td[contains(@class, "col-discount-unit")])', $second));
            $this->assertStringContainsString($mode === 'customer' ? '۹۸٬۳۳۳' : '۵٬۰۰۰', $xpath->evaluate('string(.//td[contains(@class, "col-discount-total")])', $second));
            $this->assertStringContainsString('۲۹۵٬۰۰۰', $xpath->evaluate('string(.//td[contains(@class, "col-total")])', $second));
            $this->assertStringContainsString('۱۳٬۰۰۰', $html);
        }
    }

    public function test_warehouse_invoice_print_shows_product_prices_and_goods_total_without_shipping_or_customer_finances(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 3, 'price' => 100_000, 'line_discount_amount' => 3_000],
            ['product_id' => 7, 'source_warehouse_id' => 6, 'quantity' => 2, 'price' => 100_000, 'line_discount_amount' => 2_000],
            ['product_id' => 7, 'quantity' => 1, 'price' => 120_000, 'line_discount_amount' => 0],
            ['product_id' => 8, 'quantity' => 1, 'price' => 100_000, 'line_discount_amount' => 0],
        ], 10_000, 25_000);

        $service = $this->printService();
        $warehouse = $service->invoiceData($invoice, 'warehouse');
        $this->assertSame(715_000, $warehouse['totals']['itemsNetSubtotal']);
        $this->assertSame(10_000, $warehouse['totals']['invoiceDiscount']);
        $this->assertSame(705_000, $warehouse['totals']['subtotalAfterDiscount']);
        $this->assertSame(730_000, $warehouse['totals']['total']);

        $html = view('prints.invoice', ['printData' => $warehouse])->render();
        $this->assertStringContainsString('قیمت واحد', $html);
        $this->assertStringContainsString('مبلغ نهایی', $html);
        $this->assertStringContainsString('۱۰۰٬۰۰۰', $html);
        $this->assertStringContainsString('۲۹۷٬۰۰۰', $html);
        $this->assertStringContainsString('۱۹۸٬۰۰۰', $html);
        $this->assertStringContainsString('۱۲۰٬۰۰۰', $html);
        $this->assertStringContainsString('تخفیف واحد', $html);
        $this->assertSame(0, substr_count($html, 'تخفیف این کالا'));
        $this->assertStringContainsString('تخفیف کلی فاکتور', $html);
        $this->assertStringContainsString('جمع ارزش کالاها', $html);
        $this->assertStringContainsString('۷۰۵٬۰۰۰', $html);
        $this->assertStringNotContainsString('۷۳۰٬۰۰۰', $html); // Shipping must not enter the displayed goods total.
        $this->assertStringNotContainsString('۲۵٬۰۰۰', $html);
        $this->assertStringNotContainsString('هزینه ارسال', $html);
        $this->assertStringNotContainsString('مبلغ پرداخت‌شده', $html);
        $this->assertStringNotContainsString('مانده کل حساب مشتری', $html);
        $this->assertStringNotContainsString('مشخصات واریز', $html);
        $this->assertStringContainsString('کنترل جمع‌آوری', $html);
        $this->assertStringContainsString('شوروم', $html);
        $this->assertStringContainsString('collection-checkbox', $html);

        $customerHtml = view('prints.invoice', ['printData' => $service->invoiceData($invoice, 'customer')])->render();
        $this->assertStringContainsString('هزینه ارسال', $customerHtml);
        $this->assertStringContainsString('۷۳۰٬۰۰۰', $customerHtml);
        $this->assertStringContainsString('مبلغ پرداخت‌شده', $customerHtml);
    }

    public function test_warehouse_preinvoice_remains_an_operational_copy_without_prices(): void
    {
        $preinvoice = $this->preinvoice([
            ['product_id' => 7, 'quantity' => 2, 'price' => 100_000, 'line_discount_amount' => 5_000],
        ], 10_000, 25_000);

        $html = view('prints.invoice', [
            'printData' => $this->printService()->preinvoiceData($preinvoice, 'warehouse'),
        ])->render();

        $this->assertStringContainsString('کنترل جمع‌آوری', $html);
        $this->assertStringNotContainsString('قیمت واحد', $html);
        $this->assertStringNotContainsString('مبلغ خالص', $html);
        $this->assertStringNotContainsString('جمع ارزش کالاها', $html);
    }

    public function test_warehouse_sales_invoice_hides_signatures_but_customer_and_preinvoice_copies_keep_them(): void
    {
        $invoice = $this->invoice([
            ['product_id' => 7, 'quantity' => 2, 'price' => 150_000, 'line_discount_amount' => 0],
        ], 0, 0);

        $service = $this->printService();
        $warehouseHtml = view('prints.invoice', [
            'printData' => $service->invoiceData($invoice, 'warehouse'),
        ])->render();
        $this->assertStringNotContainsString('<section class="signature-section">', $warehouseHtml);
        $this->assertStringNotContainsString('امضا و مهر فروشنده</div>', $warehouseHtml);
        $this->assertStringNotContainsString('امضای مشتری / تحویل‌گیرنده</div>', $warehouseHtml);
        $this->assertStringContainsString('قیمت واحد', $warehouseHtml);
        $this->assertStringContainsString('کنترل جمع‌آوری', $warehouseHtml);

        $customerHtml = view('prints.invoice', [
            'printData' => $service->invoiceData($invoice, 'customer'),
        ])->render();
        $this->assertStringContainsString('<section class="signature-section">', $customerHtml);
        $this->assertStringContainsString('امضا و مهر فروشنده</div>', $customerHtml);
        $this->assertStringContainsString('امضای مشتری / تحویل‌گیرنده</div>', $customerHtml);

        $preinvoice = $this->preinvoice([
            ['product_id' => 7, 'quantity' => 1, 'price' => 150_000, 'line_discount_amount' => 0],
        ], 0, 0);
        $preinvoiceWarehouseHtml = view('prints.invoice', [
            'printData' => $service->preinvoiceData($preinvoice, 'warehouse'),
        ])->render();
        $this->assertStringContainsString('<section class="signature-section">', $preinvoiceWarehouseHtml);
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
        $this->assertStringContainsString('variant-row', $html);
        $this->assertStringNotContainsString('model-schedule', $html);
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
