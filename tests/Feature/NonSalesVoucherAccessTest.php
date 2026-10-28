<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\User;
use App\Models\WarehouseTransfer;
use App\Services\WarehouseStockService;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
function nonSalesVoucherUser(): User
{
    $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
    $user->assignRole(Role::findOrCreate('Org:warehouse.non_sales_vouchers', 'web'));
    foreach (['page.warehouse.issues', 'page.assets', 'page.sales.returns'] as $key) {
        $user->permissions()->attach(DB::table('permissions')->where('key', $key)->value('id'));
    }
    foreach (PageAccessCatalog::page('assets')['action_permissions'] as $key) {
        $user->permissions()->attach(DB::table('permissions')->where('key', $key)->value('id'));
    }

    return $user->fresh();
}
it('blocks sales routes even when the general warehouse voucher page is granted', function () {
    $u = nonSalesVoucherUser();
    $this->actingAs($u);
    foreach (['vouchers.sales.index', 'vouchers.sales.show', 'vouchers.sales.print', 'vouchers.sale-delivery.index', 'purchases.index'] as $route) {
        expect(PageAccessCatalog::userCanRoute($u, $route), $route)->toBeFalse();
    }
    $this->get(route('vouchers.sales.index'))->assertForbidden();
    $this->get(route('vouchers.section.index', 'sale'))->assertForbidden();
    $this->postJson(route('vouchers.section.store', 'sale'), [])->assertForbidden();
    $this->postJson(route('vouchers.store'), ['voucher_type' => 'sale'])->assertForbidden();
});
it('blocks direct sale document access and type substitution', function () {
    $u = nonSalesVoucherUser();
    $central = WarehouseStockService::centralWarehouseId();
    $sale = WarehouseTransfer::create(['voucher_type' => 'sale', 'from_warehouse_id' => $central, 'to_warehouse_id' => $central, 'user_id' => $u->id, 'transferred_at' => now(), 'reference' => 'SECRET-SALE']);
    $this->actingAs($u)->get(route('vouchers.show', $sale))->assertForbidden();
    $this->get(route('vouchers.edit', $sale))->assertForbidden();
    $this->putJson(route('vouchers.update', $sale), ['voucher_type' => 'personnel_asset'])->assertForbidden();
    $this->deleteJson(route('vouchers.destroy', $sale))->assertForbidden();
    expect($sale->fresh())->not->toBeNull();
    $this->get(route('warehouse.outputs'))->assertOk()->assertDontSee('SECRET-SALE');
});
it('retains permitted voucher sections and hides sales entry', function () {
    $u = nonSalesVoucherUser();
    $this->actingAs($u)->get(route('vouchers.index'))->assertOk()->assertDontSee('حواله فروش')->assertSee('تحویل به پرسنل')->assertSee('ضایعات');
    foreach (['personnel', 'transfer', 'scrap'] as $type) {
        $this->get(route('vouchers.section.index', $type))->assertOk();
    }
    $this->get(route('vouchers.section.create', 'personnel'))->assertOk();
    $this->getJson(route('vouchers.personnel.products.search', ['category_id' => 0]))->assertOk();
    expect(PageAccessCatalog::userCanRoute($u, 'asset.documents.store'))->toBeTrue()->and(PageAccessCatalog::userCanRoute($u, 'vouchers.return-from-sale.store'))->toBeTrue();
    foreach (PageAccessCatalog::page('assets')['action_permissions'] as $key) {
        $request = Request::create('/warehouse/asset-trustee/documents', 'GET');
        $request->setUserResolver(fn () => $u);
        expect(app(RoutePermissionMiddleware::class)->handle($request, fn () => response('ok'), $key)->getStatusCode())->toBe(200);
    }
});
it('preserves administrator and ordinary warehouse access', function () {
    $u = nonSalesVoucherUser();
    $u->assignRole(Role::findOrCreate('Admin', 'web'));
    $this->actingAs($u->fresh())->get(route('vouchers.sales.index'))->assertOk();
    $u->removeRole('Admin');
    $u->removeRole('Org:warehouse.non_sales_vouchers');
    $this->actingAs($u->fresh())->get(route('vouchers.sales.index'))->assertOk();
});
