<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\SellerSalesDocument;
use App\Services\Commissions\CommissionRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionSalesPreviewTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_requires_complete_filters_before_loading_the_report(): void
    {
        $this->actingAs($this->financeActor())->get(route('finance.seller-sales.index'))
            ->assertOk()->assertSee('برای مشاهده گزارش، فروشنده و بازه تاریخ را انتخاب کنید.');
    }

    public function test_includes_the_end_date_and_counts_only_invoice_level_cash(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['name' => 'فروشنده گزارش', 'is_seller' => true]);
        $invoice = $this->makeInvoice($seller, 1000, '2026-07-31 15:30:00', ['document_date' => null]);
        $invoice->forceFill(['created_at' => '2026-07-31 15:30:00'])->saveQuietly();
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 200]);
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 300]);
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cheque', 'amount' => 400]);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['seller_id' => $seller->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'invoice_number' => $invoice->uuid, 'customer' => 'مشتری']))
            ->assertOk()->assertSee($invoice->uuid)->assertSee('1,000')->assertSee('500');
    }

    public function test_excludes_other_sellers_out_of_range_and_not_converted_invoices(): void
    {
        $actor = $this->financeActor(); $seller = $this->erpUser(['is_seller' => true]); $other = $this->erpUser(['is_seller' => true]);
        $included = $this->makeInvoice($seller, 100, '2026-07-10');
        $otherSeller = $this->makeInvoice($other, 200, '2026-07-10');
        $outside = $this->makeInvoice($seller, 300, '2026-08-01');
        $notConverted = $this->makeInvoice($seller, 400, '2026-07-11');
        $notConverted->preinvoiceOrder->update(['status' => PreinvoiceOrder::STATUS_PENDING_FINANCE]);
        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller)))
            ->assertSee($included->uuid)->assertDontSee($otherSeller->uuid)->assertDontSee($outside->uuid)->assertDontSee($notConverted->uuid);
    }

    public function test_searches_invoice_number_customer_name_and_mobile(): void
    {
        $seller = $this->erpUser(['is_seller' => true]); $actor = $this->financeActor();
        $match = $this->makeInvoice($seller, 100, '2026-07-10', ['customer_name' => 'مشتری ویژه', 'customer_mobile' => '09121111111']);
        $other = $this->makeInvoice($seller, 100, '2026-07-10', ['customer_name' => 'دیگری', 'customer_mobile' => '09122222222']);
        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller, ['invoice_number' => $match->uuid, 'customer' => '09121111111'])))
            ->assertSee($match->uuid)->assertDontSee($other->uuid);
    }

    public function test_zero_sales_ratio_and_read_only_request_are_safe(): void
    {
        $seller = $this->erpUser(['is_seller' => true]); $actor = $this->financeActor();
        $invoice = $this->makeInvoice($seller, 0, '2026-07-10'); InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 10]);
        $before = [InvoicePayment::count(), PreinvoiceOrder::count(), SellerSalesDocument::count(), $invoice->fresh()->total];
        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller)))->assertOk()->assertSee('0.0٪');
        $this->assertSame($before, [InvoicePayment::count(), PreinvoiceOrder::count(), SellerSalesDocument::count(), $invoice->fresh()->total]);
    }

    public function test_pagination_preserves_all_filters(): void
    {
        $seller = $this->erpUser(['is_seller' => true]); $actor = $this->financeActor();
        for ($i = 0; $i < 26; $i++) { $this->makeInvoice($seller, 10, '2026-07-10', ['customer_name' => 'صفحه‌بندی']); }
        $response = $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller, ['invoice_number' => '100', 'customer' => 'صفحه‌بندی'])));
        $response->assertOk()->assertSee('seller_id='.$seller->id, false)->assertSee('date_from=2026-07-01', false)->assertSee('invoice_number=100', false)->assertSee('customer=', false);
    }

    public function test_summary_totals_cover_every_matching_invoice_not_just_the_first_page(): void
    {
        $seller = $this->erpUser(['is_seller' => true]);
        $actor = $this->financeActor();
        $category = Category::query()->create(['name' => 'دسته گزارش کامل']);
        $product = Product::query()->create([
            'name' => 'کالای گزارش', 'sku' => 'SUM-SKU', 'category_id' => $category->id,
            'stock' => 1000, 'reserved' => 0, 'price' => 100,
        ]);
        app(CommissionRateService::class)->setRate('category', $category->id, '10', $actor, '2026-01-01 00:00:00');

        $invoiceCount = 27;
        for ($i = 0; $i < $invoiceCount; $i++) {
            $invoice = $this->makeInvoice($seller, 100, '2026-07-10', ['customer_name' => 'گزارش کامل']);
            InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100]);
        }
        // One invoice has no matching rate, to prove the missing-rate aggregates also span all pages.
        $noRateCategory = Category::query()->create(['name' => 'دسته بدون نرخ']);
        $noRateProduct = Product::query()->create([
            'name' => 'کالای بدون نرخ', 'sku' => 'NORATE-SKU', 'category_id' => $noRateCategory->id,
            'stock' => 1000, 'reserved' => 0, 'price' => 100,
        ]);
        $missingInvoice = $this->makeInvoice($seller, 50, '2026-07-10', ['customer_name' => 'گزارش کامل']);
        InvoiceItem::query()->create(['invoice_id' => $missingInvoice->id, 'product_id' => $noRateProduct->id, 'quantity' => 1, 'price' => 50]);

        $response = $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller, ['customer' => 'گزارش کامل'])));
        $response->assertOk();

        $report = $response->viewData('report');
        $summary = $report['summary'];
        $this->assertSame($invoiceCount + 1, $summary['invoice_count']);
        $this->assertSame(($invoiceCount * 100) + 50, $summary['total_sales']);
        // 10% of 100 for each of the 27 rated invoices; the unrated invoice contributes nothing.
        $this->assertSame($invoiceCount * 10, $summary['calculated_commission_total']);
        $this->assertSame(1, $summary['missing_rate_invoice_count']);
        $this->assertSame(1, $summary['missing_rate_item_count']);
        $this->assertSame(50, $summary['missing_rate_base_total']);

        $invoices = $report['invoices'];
        $this->assertCount(25, $invoices->items());
        $this->assertSame($invoiceCount + 1, $invoices->total());
    }

    public function test_read_only_report_does_not_mutate_invoices_rates_or_documents(): void
    {
        $seller = $this->erpUser(['is_seller' => true]);
        $actor = $this->financeActor();
        $category = Category::query()->create(['name' => 'دسته فقط خواندنی']);
        $product = Product::query()->create([
            'name' => 'کالای فقط خواندنی', 'sku' => 'RO-SKU', 'category_id' => $category->id,
            'stock' => 100, 'reserved' => 0, 'price' => 100,
        ]);
        app(CommissionRateService::class)->setRate('category', $category->id, '10', $actor, '2026-01-01 00:00:00');
        $invoice = $this->makeInvoice($seller, 100, '2026-07-10');
        $item = InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100]);
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 100]);

        $snapshotBefore = [
            Invoice::query()->pluck('updated_at', 'id')->toArray(),
            InvoiceItem::query()->pluck('updated_at', 'id')->toArray(),
            InvoicePayment::query()->pluck('updated_at', 'id')->toArray(),
            PreinvoiceOrder::query()->pluck('updated_at', 'id')->toArray(),
            CommissionRateRevision::query()->pluck('updated_at', 'id')->toArray(),
            SellerSalesDocument::count(),
        ];

        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->filters($seller)))->assertOk();

        $snapshotAfter = [
            Invoice::query()->pluck('updated_at', 'id')->toArray(),
            InvoiceItem::query()->pluck('updated_at', 'id')->toArray(),
            InvoicePayment::query()->pluck('updated_at', 'id')->toArray(),
            PreinvoiceOrder::query()->pluck('updated_at', 'id')->toArray(),
            CommissionRateRevision::query()->pluck('updated_at', 'id')->toArray(),
            SellerSalesDocument::count(),
        ];

        $this->assertEquals($snapshotBefore, $snapshotAfter);
        $this->assertSame(100, $invoice->fresh()->total);
        $this->assertSame(100, $item->fresh()->price);
    }

    private function filters($seller, array $extra = []): array
    {
        return array_merge(['seller_id' => $seller->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $extra);
    }
}
