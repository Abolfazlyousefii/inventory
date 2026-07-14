<?php
namespace Tests\Feature;
use App\Models\Invoice; use App\Models\PreinvoiceOrder; use App\Models\User;
class MyPreinvoiceWorkspaceNoDuplicatesTest extends MyPreinvoiceWorkspaceTest
{
    public function test_converted_preinvoice_and_invoice_are_rendered_as_one_business_document(): void
    { $seller=User::factory()->create(); $order=$this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid'=>'PI-DUP']); $this->invoice($order, Invoice::STATUS_PENDING_COLLECTION, ['uuid'=>'INV-DUP']); $content=$this->actingAs($seller)->get(route('preinvoice.my.index'))->assertOk()->getContent(); $this->assertSame(1, substr_count($content,'INV-DUP')); }
}
