<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentIndexTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_index_responds_and_contains_create_button_without_delete_action(): void
    {
        $response = $this->actingAs($this->financeActor())->get(route('finance.seller-sales.index'));
        $response->assertOk()->assertSee('ثبت سند جدید')->assertDontSee('حذف');
        $this->assertFalse(app('router')->getRoutes()->getByName('finance.seller-sales.destroy') !== null);
    }

    public function test_each_document_is_rendered_as_one_table_row_with_required_metadata(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser(['name' => 'فروشنده اول']);
        $document = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);

        $this->actingAs($actor)->get(route('finance.seller-sales.index'))
            ->assertOk()->assertSee($document->document_number)->assertSee('فروشنده اول')->assertSee($actor->name);
    }

    public function test_index_uses_server_side_pagination_and_filters(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        for ($i = 0; $i < 21; $i++) {
            $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        }
        $response = $this->actingAs($actor)->get(route('finance.seller-sales.index', ['document_number' => 'SC-000001']));
        $response->assertOk()->assertSee('SC-000001')->assertDontSee('SC-000021');
    }

    public function test_index_filters_by_user_and_document_period(): void
    {
        $actor = $this->financeActor();
        $julyUser = $this->erpUser(['name' => 'فروشنده تیر']);
        $augustUser = $this->erpUser(['name' => 'فروشنده مرداد']);
        $july = $this->createCommissionDocument($julyUser, [$this->makeInvoice($julyUser, 100, '2026-07-10 10:00:00')], $actor);
        $august = $this->createCommissionDocument(
            $augustUser,
            [$this->makeInvoice($augustUser, 200, '2026-08-10 10:00:00')],
            $actor,
            ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'],
        );

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['user_id' => $julyUser->id]))
            ->assertOk()
            ->assertSee(route('finance.seller-sales.show', $july), false)
            ->assertDontSee(route('finance.seller-sales.show', $august), false);

        $this->actingAs($actor)->get(route('finance.seller-sales.index', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']))
            ->assertOk()
            ->assertSee(route('finance.seller-sales.show', $august), false)
            ->assertDontSee(route('finance.seller-sales.show', $july), false);
    }
}
