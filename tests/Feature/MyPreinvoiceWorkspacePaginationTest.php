<?php
namespace Tests\Feature;
use App\Models\PreinvoiceOrder; use App\Models\User;
class MyPreinvoiceWorkspacePaginationTest extends MyPreinvoiceWorkspaceTest
{
    public function test_pagination_uses_page_parameter_and_preserves_query_string(): void
    { $seller=User::factory()->create(); foreach(range(1,21) as $i){$this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid'=>sprintf('PI-PAGE-%02d',$i),'customer_name'=>'پیج تست']);} $this->actingAs($seller)->get(route('preinvoice.my.index',['tab'=>'active','q'=>'پیج','page'=>2]))->assertOk()->assertSee('tab=active', false)->assertSee('q=%D9%BE%DB%8C%D8%AC', false); }
}
