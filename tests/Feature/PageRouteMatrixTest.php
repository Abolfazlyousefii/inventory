<?php

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function pageMatrixUser(string $pagePermission): User
{
    $permission = Permission::query()->where('key', $pagePermission)->first() ?? Permission::findOrCreate($pagePermission, 'web');
    if ($permission->key !== $pagePermission) $permission->forceFill(['key' => $pagePermission])->save();
    $role = Role::findOrCreate('Matrix-'.str_replace('.', '-', $pagePermission), 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function pageMatrixRequest(User $user, string $routeName): Request
{
    $request = Request::create('/matrix/'.$routeName, 'GET');
    $route = new Route(['GET'], '/matrix/'.$routeName, fn () => response('ok'));
    $route->name($routeName);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    return $request;
}

$matrixPages = [
    'products', 'commercial.commissions', 'sales.preinvoices', 'sales.preinvoice_warehouse_review',
    'sales.preinvoice_finance_review', 'sales.invoices', 'sales.returns',
    'customers', 'warehouse.stocks', 'warehouse.collection', 'warehouse.shipping',
    'users', 'roles',
];

it('allows every explicitly owned route through the authorization layer', function (string $pageKey) {
    $page = PageAccessCatalog::page($pageKey);
    $user = pageMatrixUser($page['permission']);

    expect($page['routes'])->not->toBeEmpty();
    foreach ($page['routes'] as $routeName) {
        $response = app(EnsurePageAccess::class)->handle(pageMatrixRequest($user, $routeName), fn () => response('ok'));
        expect($response->getStatusCode(), $pageKey.':'.$routeName)->toBe(200);
    }
})->with($matrixPages);

it('denies a user without the owning page permission', function (string $pageKey) {
    $routeName = PageAccessCatalog::page($pageKey)['routes'][0];
    $user = User::factory()->create();

    expect(fn () => app(EnsurePageAccess::class)->handle(pageMatrixRequest($user, $routeName), fn () => response('ok')))
        ->toThrow(HttpException::class);
})->with($matrixPages);
