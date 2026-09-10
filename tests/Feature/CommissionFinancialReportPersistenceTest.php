<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CommissionRateRevision;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SellerSalesDocument;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class CommissionFinancialReportPersistenceTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_report_creation_snapshots_commission_and_blocks_duplicate_invoice(): void
    {
        [$actor, $seller, $invoice] = $this->fixture();
        $response = $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [$invoice]));
        $document = SellerSalesDocument::firstOrFail();
        $response->assertRedirect(route('finance.seller-sales.show', $document));
        $this->assertSame('draft', $document->status);
        $this->assertSame(200_000, $document->total_commission_amount);
        $this->assertDatabaseHas('seller_sales_document_items', ['invoice_id'=>$invoice->id, 'rate_snapshot'=>'2.0000', 'commission_amount'=>200_000]);
        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [$invoice]))->assertSessionHasErrors('invoice_ids');
    }

    public function test_draft_can_be_edited_deleted_and_releases_its_invoice(): void
    {
        [$actor, $seller, $first] = $this->fixture();
        $second = $this->makeInvoice($seller, 10_000_000, '2026-07-11', ['uuid'=>'33333']);
        $product = Product::firstOrFail();
        InvoiceItem::create(['invoice_id'=>$second->id,'product_id'=>$product->id,'quantity'=>1,'price'=>10_000_000,'line_discount_amount'=>0]);
        $document = app(\App\Services\Finance\SellerCommissionDocumentService::class)->createDocument($this->documentData($seller, [$first]), $actor);
        $this->actingAs($actor)->put(route('finance.seller-sales.update', $document), $this->documentData($seller, [$second]))->assertRedirect();
        $this->assertDatabaseHas('seller_sales_document_items', ['seller_sales_document_id'=>$document->id,'invoice_id'=>$second->id]);
        $this->actingAs($actor)->delete(route('finance.seller-sales.destroy', $document))->assertRedirect(route('finance.seller-sales.index'));
        $this->assertDatabaseMissing('seller_sales_documents', ['id'=>$document->id]);
        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [$second]))->assertSessionDoesntHaveErrors('invoice_ids');
    }

    public function test_print_works_and_snapshot_survives_rate_change(): void
    {
        [$actor, $seller, $invoice, $rate] = $this->fixture();
        $document = app(\App\Services\Finance\SellerCommissionDocumentService::class)->createDocument($this->documentData($seller, [$invoice]), $actor);
        $rate->update(['percentage'=>'9.0000']);
        $this->assertSame('2.0000', $document->items()->firstOrFail()->rate_snapshot);
        $this->actingAs($actor)->get(route('finance.seller-sales.print', $document))->assertOk()->assertSee($document->document_number)->assertSee('2.0000');
    }

    private function fixture(): array
    {
        $actor=$this->financeActor();$seller=$this->erpUser(['is_seller'=>true]);
        $category=Category::create(['name'=>'گروه']);$product=Product::create(['name'=>'کالا','sku'=>(string)Str::uuid(),'category_id'=>$category->id,'stock'=>1,'reserved'=>0,'price'=>10_000_000]);
        $rate=CommissionRateRevision::create(array_merge(['target_type'=>'product','target_id'=>$product->id,'target_key'=>CommissionTarget::key('product',$product->id),'active_marker'=>1,'percentage'=>'2.0000','effective_from'=>'2025-01-01','created_by'=>$seller->id],CommissionTarget::foreignKeys('product',$product->id)));
        $invoice=$this->makeInvoice($seller,10_000_000,'2026-07-10',['uuid'=>'22222']);InvoiceItem::create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'quantity'=>1,'price'=>10_000_000,'line_discount_amount'=>0]);
        return [$actor,$seller,$invoice,$rate];
    }
}
