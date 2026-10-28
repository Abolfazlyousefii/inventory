<?php

use App\Models\Invoice;
use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
function incomingShippingUser(): User
{
    $u = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
    $u->permissions()->attach(DB::table('permissions')->where('key', 'page.warehouse.shipping')->value('id'));

    return $u;
}
function incomingShippingInvoice(string $status, ?string $address = 'آدرس ثبت‌شده روی فاکتور'): Invoice
{
    return Invoice::withoutEvents(fn () => Invoice::create(['uuid' => 'IN-'.Str::random(10), 'customer_name' => 'مشتری نمونه', 'customer_address' => $address, 'status' => $status, 'subtotal' => 100, 'total' => 100]));
}
it('lists only incoming collection invoices with their own address without modifying data', function () {
    $u = incomingShippingUser();
    $first = incomingShippingInvoice(Invoice::STATUS_PENDING_COLLECTION);
    incomingShippingInvoice(Invoice::STATUS_WAREHOUSE_RECEIVED);
    $missing = incomingShippingInvoice(Invoice::STATUS_COLLECTING, null);
    foreach ([Invoice::STATUS_READY_TO_SHIP, Invoice::STATUS_SHIPPED, Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_NOT_SHIPPED] as $status) {
        incomingShippingInvoice($status);
    }
    $cancelled = incomingShippingInvoice(Invoice::STATUS_COLLECTING);
    $cancelled->update(['cancelled_at' => now()]);
    $before = DB::table('invoices')->orderBy('id')->get()->toJson();
    $r = $this->actingAs($u)->getJson('/warehouse/shipping/incoming')->assertOk()->assertJsonPath('total', 3)->assertJsonCount(3, 'data');
    expect(collect($r->json('data'))->firstWhere('uuid', $first->uuid)['address'])->toBe($first->customer_address)
        ->and(collect($r->json('data'))->firstWhere('uuid', $missing->uuid)['address'])->toBeNull()
        ->and(DB::table('invoices')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(array_keys($r->json('data.0')))->toBe(['uuid', 'customer_name', 'status', 'status_label', 'address']);
    expect(PageAccessCatalog::userCanRoute($u, 'vouchers.sales.queue.complete-collection'))->toBeFalse();
    $this->get(route('warehouse.shipping.index'))->assertOk()->assertSee('در راه ارسال')->assertSee('shippingIncomingModal');
});
it('requires shipping access and keeps addresses private', function () {
    incomingShippingInvoice(Invoice::STATUS_COLLECTING);
    $this->actingAs(User::factory()->create(['is_active' => true, 'can_access_erp' => true]))->getJson('/warehouse/shipping/incoming')->assertForbidden();
});
it('paginates incoming invoices and excludes a completed invoice on the next read', function () {
    $u = incomingShippingUser();
    for ($i = 0; $i < 22; $i++) {
        incomingShippingInvoice(Invoice::STATUS_COLLECTING);
    }
    $this->actingAs($u)->getJson('/warehouse/shipping/incoming')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('next_page', 2);
    $r = $this->getJson('/warehouse/shipping/incoming?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('next_page', null);
    Invoice::where('uuid', $r->json('data.0.uuid'))->update(['status' => Invoice::STATUS_READY_TO_SHIP]);
    $this->getJson('/warehouse/shipping/incoming')->assertOk()->assertJsonPath('total',21);
});
