<?php
namespace Tests\Feature;
use App\Models\Invoice; use App\Models\PreinvoiceOrder; use App\Models\User;
class MyPreinvoiceWorkspaceSearchTest extends MyPreinvoiceWorkspaceTest
{
    public function test_search_matches_document_number_customer_and_mobile_inside_active_tab(): void
    { $seller=User::factory()->create(); $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid'=>'PI-SEARCH','customer_name'=>'مریم احمدی','customer_mobile'=>'09121112233']); $order=$this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE); $this->invoice($order, Invoice::STATUS_PENDING_COLLECTION, ['uuid'=>'INV-SEARCH']); $this->actingAs($seller)->get(route('preinvoice.my.index',['q'=>'09121112233']))->assertOk()->assertSee('PI-SEARCH')->assertDontSee('INV-SEARCH'); $this->actingAs($seller)->get(route('preinvoice.my.index',['q'=>'INV-SEARCH']))->assertOk()->assertSee('INV-SEARCH'); }
}
