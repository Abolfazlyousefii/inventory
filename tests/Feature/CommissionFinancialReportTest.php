<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRateRevision;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SellerSalesDocument;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class CommissionFinancialReportTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_report_invoice_filtering_and_search_work(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $inside = $this->makeInvoice($seller, 1000, '2026-07-10 12:00:00', ['uuid' => '12345', 'customer_name' => 'مشتری هدف']);
        $outside = $this->makeInvoice($seller, 2000, '2026-08-10 12:00:00');

        $response = $this->actingAs($actor)->getJson(route('finance.seller-sales.report-invoices', ['date_from'=>'2026-07-01', 'date_to'=>'2026-07-31', 'search'=>'12345']));

        $response->assertOk()->assertJsonPath('data.0.id', $inside->id)->assertJsonStructure(['current_page', 'last_page', 'total']);
        $this->assertNotContains($outside->id, $response->json('data.*.id'));
    }

    public function test_manual_invoice_outside_range_is_returned_and_can_be_selected(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($seller, 3000, '2026-06-28 12:00:00', ['uuid' => '54321']);

        $this->actingAs($actor)->getJson(route('finance.seller-sales.manual-invoice', ['invoice_number'=>'54321', 'date_from'=>'2026-07-01', 'date_to'=>'2026-07-31']))
            ->assertOk()->assertJsonPath('id', $invoice->id)->assertJsonPath('date_iso', '2026-06-28')->assertJsonPath('outside_range', true);
    }

    public function test_preview_calculates_commission_without_writing_records(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $invoice = $this->commissionInvoice($seller);

        $this->actingAs($actor)->post(route('finance.seller-sales.preview'), ['date_from'=>'2026-07-01', 'date_to'=>'2026-07-31', 'invoice_ids'=>[$invoice->id]])
            ->assertOk()->assertSee('200,000')->assertSee('2.0000');

        $this->assertDatabaseCount((new CommissionLedgerEntry)->getTable(), 0);
        $this->assertDatabaseCount((new SellerSalesDocument)->getTable(), 0);
    }

    public function test_selected_invoice_ids_are_validated(): void
    {
        $actor = $this->financeActor();
        $this->actingAs($actor)->post(route('finance.seller-sales.preview'), ['date_from'=>'2026-07-01', 'date_to'=>'2026-07-31', 'invoice_ids'=>[999999]])
            ->assertSessionHasErrors('invoice_ids.0');
    }

    private function commissionInvoice($seller)
    {
        $category = Category::create(['name'=>'گروه تست']);
        $product = Product::create(['name'=>'کالای تست','sku'=>(string) Str::uuid(),'category_id'=>$category->id,'stock'=>1,'reserved'=>0,'price'=>10_000_000]);
        CommissionRateRevision::create(array_merge(['target_type'=>'product','target_id'=>$product->id,'target_key'=>CommissionTarget::key('product',$product->id),'active_marker'=>1,'percentage'=>'2.0000','effective_from'=>'2025-01-01','created_by'=>$seller->id], CommissionTarget::foreignKeys('product',$product->id)));
        $invoice = $this->makeInvoice($seller, 10_000_000, '2026-07-10 12:00:00', ['uuid'=>'22222']);
        InvoiceItem::create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'quantity'=>1,'price'=>10_000_000,'line_discount_amount'=>0]);
        return $invoice;
    }
}
