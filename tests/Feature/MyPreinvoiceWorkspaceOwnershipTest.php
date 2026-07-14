<?php
namespace Tests\Feature;
use App\Models\PreinvoiceOrder;
use App\Models\User;
class MyPreinvoiceWorkspaceOwnershipTest extends MyPreinvoiceWorkspaceTest
{
    public function test_counts_and_rows_are_scoped_to_current_seller(): void
    {
        $seller = User::factory()->create(); $other = User::factory()->create();
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid'=>'PI-MINE']);
        $this->preinvoice($other, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid'=>'PI-OTHER']);
        $this->actingAs($seller)->get(route('preinvoice.my.index'))->assertOk()->assertSee('PI-MINE')->assertDontSee('PI-OTHER')->assertSee('پیش‌فاکتورهای من <span class="count">(1)</span>', false);
    }
}
