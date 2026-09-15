<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionOperationsHubTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    private function range(array $extra = []): array
    {
        return array_merge(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $extra);
    }

    public function test_hub_page_exposes_both_report_and_registered_document_tabs(): void
    {
        $this->actingAs($this->financeActor())
            ->get(route('finance.seller-sales.index'))
            ->assertOk()
            ->assertSee('مدیریت پورسانت فروشندگان')
            ->assertSee('مشاهده فروش، بررسی پورسانت و مدیریت اسناد ثبت‌شده')
            ->assertSee('گزارش و صدور سند')
            ->assertSee('اسناد ثبت‌شده');
    }

    public function test_report_filters_are_labelled_and_keep_the_helper_message(): void
    {
        $response = $this->actingAs($this->financeActor())->get(route('finance.seller-sales.index'));

        $response->assertOk()
            ->assertSee('فروشنده')
            ->assertSee('از تاریخ')
            ->assertSee('تا تاریخ')
            ->assertSee('شماره فاکتور')
            ->assertSee('نام یا موبایل مشتری')
            ->assertSee('نمایش گزارش')
            ->assertSee('پاک‌کردن فیلترها')
            ->assertSee('برای مشاهده گزارش، فروشنده و بازه تاریخ را انتخاب کنید.')
            ->assertSee('for="reportSellerId"', false)
            ->assertSee('for="reportDateFrom"', false)
            ->assertSee('for="reportDateTo"', false)
            ->assertSee('for="reportInvoiceNumber"', false)
            ->assertSee('for="reportCustomer"', false);
    }

    public function test_issue_document_cta_is_not_available_without_a_seller_and_a_full_date_range(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);

        $this->actingAs($actor)->get(route('finance.seller-sales.index'))
            ->assertOk()->assertDontSee('صدور سند پورسانت از این گزارش');

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['seller_id' => $seller->id]))
            ->assertOk()->assertDontSee('صدور سند پورسانت از این گزارش');

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['seller_id' => $seller->id, 'date_from' => '2026-07-01']))
            ->assertOk()->assertDontSee('صدور سند پورسانت از این گزارش');
    }

    public function test_invalid_date_range_shows_a_persian_error_instead_of_the_report(): void
    {
        $seller = $this->erpUser(['is_seller' => true]);

        $response = $this->actingAs($this->financeActor())->get(route('finance.seller-sales.index', [
            'seller_id' => $seller->id, 'date_from' => '2026-07-31', 'date_to' => '2026-07-01',
        ]));

        $response->assertOk()
            ->assertSee('بازه تاریخ نامعتبر است؛ «از تاریخ» نمی‌تواند بعد از «تا تاریخ» باشد.')
            ->assertDontSee('صدور سند پورسانت از این گزارش');
        $this->assertNull($response->viewData('report'));
    }

    public function test_valid_filters_link_the_cta_to_the_real_create_document_flow(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $this->makeInvoice($seller, 1000, '2026-07-10');

        $expected = route('finance.seller-sales.create', ['seller_id' => $seller->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->range(['seller_id' => $seller->id])))
            ->assertOk()
            ->assertSee('صدور سند پورسانت از این گزارش')
            ->assertSee(e($expected), false);
    }

    public function test_cta_target_prefills_the_existing_create_form_with_seller_and_range(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['name' => 'فروشنده سند', 'is_seller' => true]);

        $this->actingAs($actor)->get(route('finance.seller-sales.create', ['seller_id' => $seller->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('value="'.$seller->id.'" selected', false)
            ->assertSee('value="2026-07-01"', false)
            ->assertSee('value="2026-07-31"', false)
            ->assertSee(route('finance.seller-sales.store'), false);
    }

    public function test_registered_documents_are_listed_with_their_metadata_and_actions(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['name' => 'فروشنده فهرست']);
        $document = $this->createCommissionDocument($seller, [$this->makeInvoice($seller, 4500)], $actor);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['tab' => 'documents']))
            ->assertOk()
            ->assertSee($document->document_number)
            ->assertSee('فروشنده فهرست')
            ->assertSee($actor->name)
            ->assertSee(e(route('finance.seller-sales.show', $document)), false)
            ->assertSee(e(route('finance.seller-sales.print', $document)), false)
            ->assertSee(e(route('finance.seller-sales.edit', $document)), false)
            ->assertSee(e(route('finance.seller-sales.destroy', $document)), false)
            ->assertSee('آیا از حذف این سند پورسانت مطمئن هستید؟', false);
    }

    public function test_empty_state_is_shown_when_no_documents_exist(): void
    {
        $this->actingAs($this->financeActor())->get(route('finance.seller-sales.index', ['tab' => 'documents']))
            ->assertOk()->assertSee('هنوز هیچ سند پورسانتی ثبت نشده است.');
    }

    public function test_existing_view_print_edit_and_delete_routes_stay_reachable(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser();
        $document = $this->createCommissionDocument($seller, [$this->makeInvoice($seller, 700)], $actor);

        $this->actingAs($actor)->get(route('finance.seller-sales.show', $document))->assertOk();
        $this->actingAs($actor)->get(route('finance.seller-sales.print', $document))->assertOk();
        $this->actingAs($actor)->get(route('finance.seller-sales.edit', $document))->assertOk();

        $this->actingAs($actor)->delete(route('finance.seller-sales.destroy', $document))
            ->assertRedirect(route('finance.seller-sales.index'));
        $this->assertDatabaseMissing('seller_sales_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('seller_sales_document_items', ['seller_sales_document_id' => $document->id]);
    }

    public function test_an_invoice_already_used_in_a_document_is_not_selectable_for_another_document(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser();
        $used = $this->makeInvoice($seller, 100);
        $free = $this->makeInvoice($seller, 200);
        $this->createCommissionDocument($seller, [$used], $actor);

        $ids = collect($this->actingAs($actor)
            ->getJson(route('finance.seller-sales.available-invoices', $this->range(['user_id' => $seller->id])))
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($used->id, $ids);

        // The server-side guard refuses it even if the id is posted directly.
        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [$used]))
            ->assertSessionHasErrors('invoice_ids');
        $this->assertSame(1, SellerSalesDocument::query()->count());
        $this->assertSame(1, SellerSalesDocumentItem::query()->where('active_invoice_id', $used->id)->count());
    }

    public function test_invoices_of_another_seller_or_outside_the_range_are_not_selectable(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser();
        $other = $this->erpUser();
        $inRange = $this->makeInvoice($seller, 100, '2026-07-10');
        $outside = $this->makeInvoice($seller, 200, '2026-08-05');
        $foreign = $this->makeInvoice($other, 300, '2026-07-10');

        $ids = collect($this->actingAs($actor)
            ->getJson(route('finance.seller-sales.available-invoices', $this->range(['user_id' => $seller->id])))
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($inRange->id, $ids);
        $this->assertNotContains($outside->id, $ids);
        $this->assertNotContains($foreign->id, $ids);

        foreach ([$outside, $foreign] as $invoice) {
            $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [$invoice]))
                ->assertSessionHasErrors('invoice_ids');
        }
        $this->assertSame(0, SellerSalesDocument::query()->count());
    }

    public function test_the_create_form_cannot_save_a_document_without_valid_invoices(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser();

        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($seller, [], ['invoice_ids' => []]))
            ->assertSessionHasErrors('invoice_ids');
        $this->assertSame(0, SellerSalesDocument::query()->count());
    }

    public function test_report_and_document_list_get_requests_are_read_only(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($seller, 1000, '2026-07-10');
        $document = $this->createCommissionDocument($seller, [$this->makeInvoice($seller, 250)], $actor);

        $snapshot = fn () => [
            Invoice::query()->pluck('updated_at', 'id')->toArray(),
            PreinvoiceOrder::query()->pluck('updated_at', 'id')->toArray(),
            SellerSalesDocument::query()->pluck('updated_at', 'id')->toArray(),
            SellerSalesDocumentItem::query()->pluck('updated_at', 'id')->toArray(),
        ];

        $before = $snapshot();

        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->range(['seller_id' => $seller->id])))->assertOk();
        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['tab' => 'documents']))->assertOk();
        $this->actingAs($actor)->get(route('finance.seller-sales.create', $this->range(['seller_id' => $seller->id])))->assertOk();
        $this->actingAs($actor)->get(route('finance.seller-sales.show', $document))->assertOk();
        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', $this->range(['user_id' => $seller->id])))->assertOk();

        $this->assertEquals($before, $snapshot());
        $this->assertSame(1000, $invoice->fresh()->total);
        $this->assertSame(1, SellerSalesDocument::query()->count());
    }

    public function test_document_list_filters_by_seller_period_and_document_number(): void
    {
        $actor = $this->financeActor();
        $july = $this->erpUser(['name' => 'فروشنده تیرماه']);
        $august = $this->erpUser(['name' => 'فروشنده مردادماه']);
        $julyDocument = $this->createCommissionDocument($july, [$this->makeInvoice($july, 100, '2026-07-10')], $actor);
        $augustDocument = $this->createCommissionDocument(
            $august,
            [$this->makeInvoice($august, 200, '2026-08-10')],
            $actor,
            ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'],
        );

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['tab' => 'documents', 'user_id' => $july->id]))
            ->assertOk()
            ->assertSee(e(route('finance.seller-sales.show', $julyDocument)), false)
            ->assertDontSee(e(route('finance.seller-sales.show', $augustDocument)), false);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['tab' => 'documents', 'document_number' => $augustDocument->document_number]))
            ->assertOk()
            ->assertSee(e(route('finance.seller-sales.show', $augustDocument)), false)
            ->assertDontSee(e(route('finance.seller-sales.show', $julyDocument)), false);
    }

    public function test_report_pagination_preserves_every_filter(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        for ($i = 0; $i < 26; $i++) {
            $this->makeInvoice($seller, 10, '2026-07-10', ['customer_name' => 'صفحه‌بندی']);
        }

        $this->actingAs($actor)->get(route('finance.seller-sales.index', $this->range(['seller_id' => $seller->id, 'customer' => 'صفحه‌بندی'])))
            ->assertOk()
            ->assertSee('seller_id='.$seller->id, false)
            ->assertSee('date_from=2026-07-01', false)
            ->assertSee('date_to=2026-07-31', false);
    }

    public function test_the_hub_never_links_to_the_retired_commercial_commission_automation(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $this->createCommissionDocument($seller, [$this->makeInvoice($seller, 100)], $actor);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['tab' => 'documents']))
            ->assertOk()
            ->assertDontSee('/commercial/commissions', false);
    }
}
