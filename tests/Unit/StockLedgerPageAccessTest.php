<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use App\Support\{PageAccessCatalog, PermissionCatalog};
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class StockLedgerPageAccessTest extends TestCase
{
    public function test_stock_history_has_the_same_page_and_legacy_permission_as_other_ledgers(): void
    {
        $this->assertSame(['page.products'],PageAccessCatalog::permissionsForRoute('products.stock-ledger'));
        $this->assertSame('products.ledger',PermissionCatalog::routePermissions()['products.stock-ledger']);
    }

    private function check(?array $permissions): int
    {
        $request=Request::create('/products/798/stock-ledger?variant_id=12187');
        $request->headers->set('Accept','application/json');
        $route=new Route(['GET'],'/products/{product}/stock-ledger',fn()=>response('ok'));
        $route->name('products.stock-ledger');$request->setRouteResolver(fn()=>$route);
        $user=null;
        if($permissions!==null){
            $user=\Mockery::mock(User::class)->makePartial();
            $user->shouldReceive('isSuperAdmin')->andReturn(false);
            $user->shouldReceive('hasPermission')->andReturnUsing(fn($key)=>in_array($key,$permissions,true));
        }
        $request->setUserResolver(fn()=>$user);
        return app(EnsurePageAccess::class)->handle($request,fn()=>response('ok'))->getStatusCode();
    }

    public function test_existing_product_access_opens_stock_history(): void
    {
        $this->assertSame(200,$this->check(['page.products']));
    }

    public function test_existing_ledger_permission_opens_stock_history(): void
    {
        $this->assertSame(200,$this->check(['products.ledger']));
    }

    public function test_unrelated_permissions_and_guests_do_not_gain_access(): void
    {
        $this->assertSame(403,$this->check(['page.dashboard']));
        $this->assertSame(403,$this->check([]));
        $this->assertSame(302,$this->check(null));
    }
}
