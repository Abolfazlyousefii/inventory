<?php
namespace Tests\Feature;
use App\Models\Invoice; use App\Models\PreinvoiceOrder; use App\Models\User;
class MyPreinvoiceWorkspaceCorrectionMetadataTest extends MyPreinvoiceWorkspaceTest
{
    public function test_correction_tab_shows_finance_and_warehouse_metadata(): void
    { $seller=User::factory()->create(); $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid'=>'PI-FINANCE','warehouse_reject_reason'=>'اصلاح قیمت']); $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE, ['uuid'=>'PI-WAREHOUSE','warehouse_reject_reason'=>'اصلاح تعداد']); $order=$this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE); $this->invoice($order, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, ['uuid'=>'INV-COLLECTION','collection_note'=>'مغایرت انبار']); $this->actingAs($seller)->get(route('preinvoice.my.index',['tab'=>'needs-correction']))->assertOk()->assertSee('PI-FINANCE')->assertSee('اصلاح قیمت')->assertSee('PI-WAREHOUSE')->assertSee('اصلاح تعداد')->assertSee('INV-COLLECTION')->assertSee('مغایرت انبار'); }
}
