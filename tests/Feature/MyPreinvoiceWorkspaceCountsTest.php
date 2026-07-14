<?php
namespace Tests\Feature;
use App\Models\Invoice; use App\Models\PreinvoiceOrder; use App\Models\User;
class MyPreinvoiceWorkspaceCountsTest extends MyPreinvoiceWorkspaceTest
{
    public function test_badges_match_bucket_counts(): void
    {
        $seller=User::factory()->create();
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid'=>'PI-D1']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid'=>'PI-A1']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid'=>'PI-R1']);
        $ship=$this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid'=>'PI-S1']); $this->invoice($ship, Invoice::STATUS_SHIPPED, ['uuid'=>'INV-S1','shipped_at'=>now()]);
        $this->actingAs($seller)->get(route('preinvoice.my.index'))->assertOk()->assertSee('پیش‌فاکتورهای من <span class="count">(1)</span>', false)->assertSee('پیش‌نویس‌ها <span class="count">(1)</span>', false)->assertSee('فاکتورهای ارسال‌شده <span class="count">(1)</span>', false)->assertSee('نیازمند بررسی و اصلاح <span class="count">(1)</span>', false);
    }
}
