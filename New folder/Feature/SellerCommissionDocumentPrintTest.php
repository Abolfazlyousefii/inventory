<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentPrintTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_show_page_reads_invoice_details_from_snapshots(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 7777, '2026-07-10 10:00:00', ['uuid' => '45678', 'customer_name' => 'مشتری اولیه']);
        $document = $this->createCommissionDocument($owner, [$invoice], $actor);
        $invoice->forceFill(['customer_name' => 'مشتری تغییر یافته', 'total' => 9999])->save();
        $this->actingAs($actor)->get(route('finance.seller-sales.show', $document))->assertOk()->assertSee('45678')->assertSee('مشتری اولیه')->assertDontSee('مشتری تغییر یافته');
    }

    public function test_print_uses_snapshot_after_invoice_is_changed(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 7777);
        $document = $this->createCommissionDocument($owner, [$invoice], $actor);
        $invoice->update(['total' => 9999]);
        $this->actingAs($actor)->get(route('finance.seller-sales.print', $document))->assertOk()->assertSee('7,777')->assertDontSee('9,999');
    }

    public function test_print_is_rtl_a4_and_has_repeating_table_header_and_signatures(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $document = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $this->actingAs($actor)->get(route('finance.seller-sales.print', $document))->assertOk()->assertSee('dir="rtl"', false)->assertSee('size:A4 portrait', false)->assertSee('display:table-header-group', false)->assertSee('امضای واحد مالی')->assertSee('امضای مدیریت');
    }

    public function test_print_has_no_application_sidebar_or_panel_header(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $document = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $this->actingAs($actor)->get(route('finance.seller-sales.print', $document))->assertOk()->assertDontSee('id="appSidebar"', false)->assertDontSee('app-topbar', false);
    }

    public function test_number_and_initial_date_snapshots_do_not_change_after_source_rows_change(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 7000, '2026-07-10 10:00:00', ['uuid' => '13579']);
        $document = $this->createCommissionDocument($owner, [$invoice], $actor);

        DB::table('invoices')->where('id', $invoice->id)->update(['uuid' => '97531', 'document_date' => '2026-08-20 10:00:00']);
        DB::table('preinvoice_orders')->where('id', $invoice->preinvoice_order_id)->update(['created_at' => '2026-08-21 10:00:00']);

        $response = $this->actingAs($actor)->get(route('finance.seller-sales.print', $document));
        $response->assertOk()->assertSee('13579')->assertDontSee('97531');
        $this->assertSame('2026-07-10 10:00:00', $document->items()->first()->invoice_date_snapshot->format('Y-m-d H:i:s'));
    }

    public function test_show_contains_document_summary_notes_creator_and_items_table(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser(['name' => 'فروشنده نمایش']);
        $document = $this->createCommissionDocument($owner, [$this->makeInvoice($owner, 8765)], $actor, ['notes' => 'توضیحات نهایی سند']);

        $this->actingAs($actor)->get(route('finance.seller-sales.show', $document))
            ->assertOk()
            ->assertSee($document->document_number)
            ->assertSee($owner->name)
            ->assertSee($actor->name)
            ->assertSee('توضیحات نهایی سند')
            ->assertSee('8,765');
    }
}
