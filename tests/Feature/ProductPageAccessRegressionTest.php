<?php

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets a non admin product page role use every routine product endpoint', function () {
    $permission = Permission::query()->where('key', 'page.products')->first()
        ?? Permission::findOrCreate('page.products', 'web');
    if ($permission->key !== 'page.products') {
        $permission->forceFill(['key' => 'page.products'])->save();
    }
    $role = Role::findOrCreate('ProductTestRole', 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    foreach (['products.index', 'products.data', 'products.create', 'products.store', 'products.edit', 'products.update', 'products.destroy', 'products.variants', 'products.image', 'products.warehouse-stock', 'products.sales-ledger', 'products.purchase-ledger', 'products.pricelist', 'admin.product-exports.print', 'admin.product-exports.products.search'] as $routeName) {
        $request = Request::create('/product-regression/'.$routeName, 'GET');
        $route = new Route(['GET'], '/product-regression/'.$routeName, fn () => response('ok'));
        $route->name($routeName);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        $response = app(EnsurePageAccess::class)->handle($request, fn () => response('ok'));
        expect($response->getStatusCode(), $routeName)->toBe(200);
    }
});
