<?php

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function preinvoiceAccessRequest(User $user, string $name): Request
{
    $request = Request::create('/preinvoice-regression/'.$name, 'GET');
    $route = new Route(['GET'], '/preinvoice-regression/'.$name, fn () => response('ok'));
    $route->name($name);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('lets a sales role use its complete normal preinvoice page but not review workflows', function () {
    $permission = Permission::findOrCreate('page.sales.preinvoices', 'web');
    $permission->forceFill(['key' => 'page.sales.preinvoices'])->save();
    $role = Role::findOrCreate('SalesTestRole', 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    $routine = [
        'preinvoice.create', 'preinvoice.draft.save', 'preinvoice.draft.edit', 'preinvoice.draft.update',
        'preinvoice.my.index', 'preinvoice.my.show', 'preinvoice.print',
        'preinvoice.api.product-finder', 'preinvoice.api.product-finder.categories',
        'preinvoice.api.products', 'preinvoice.api.product', 'api.customers.search', 'api.customers.show',
        'preinvoice.autosave', 'preinvoice.autosave.latest', 'preinvoice.autosave.discard',
        'preinvoice.api.reservations.sync', 'preinvoice.api.reservations.release',
    ];
    foreach ($routine as $routeName) {
        $response = app(EnsurePageAccess::class)->handle(preinvoiceAccessRequest($user, $routeName), fn () => response('ok'));
        expect($response->getStatusCode(), $routeName)->toBe(200);
    }

    foreach (['preinvoice.draft.finance', 'preinvoice.draft.finance.update', 'warehouse.reviews.index', 'preinvoice.warehouse.review'] as $routeName) {
        expect(fn () => app(EnsurePageAccess::class)->handle(preinvoiceAccessRequest($user, $routeName), fn () => response('ok')))
            ->toThrow(HttpException::class);
    }
});
