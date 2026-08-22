<?php

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pageAccessRequest(User $user, bool $json = false): Request
{
    $request = Request::create('/products', 'GET', [], [], [], $json ? ['HTTP_ACCEPT'=>'application/json'] : []);
    $route = new Route(['GET'], '/products', fn () => response('ok'));
    $route->name('products.index');
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);
    return $request;
}

it('returns an html 403 instead of redirecting an authenticated user without page access', function () {
    $user = User::factory()->create();
    expect(fn () => app(EnsurePageAccess::class)->handle(pageAccessRequest($user), fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('returns a json 403 for an ajax user without page access', function () {
    $response = app(EnsurePageAccess::class)->handle(pageAccessRequest(User::factory()->create(), true), fn () => response('ok'));
    expect($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('content-type'))->toContain('application/json');
});

it('does not use retained legacy direct permissions for runtime page access', function () {
    $user = User::factory()->create();
    $permissionId = DB::table('permissions')->where('key','products.view')->value('id');
    DB::table('user_permissions')->insert(['user_id'=>$user->id,'permission_id'=>$permissionId,'created_at'=>now(),'updated_at'=>now()]);
    expect(fn () => app(EnsurePageAccess::class)->handle(pageAccessRequest($user), fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('allows runtime page access only through a role page permission', function () {
    $user = User::factory()->create();
    $role = Role::findOrCreate('ProductPageRole', 'web');
    $permission = \Spatie\Permission\Models\Permission::query()->where('key', 'page.products')->firstOrFail();
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $response = app(EnsurePageAccess::class)->handle(pageAccessRequest($user), fn () => response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('allows Owner through every valid page middleware check', function () {
    $owner = Role::findOrCreate('Owner','web');
    $user = User::factory()->create();
    $user->assignRole($owner);
    $response = app(EnsurePageAccess::class)->handle(pageAccessRequest($user), fn () => response('ok'));
    expect($response->getStatusCode())->toBe(200);
});

it('fails closed when route permission middleware has no catalog mapping', function () {
    $request = Request::create('/unmapped-private', 'GET');
    $route = new Route(['GET'], '/unmapped-private', fn () => response('ok'));
    $route->name('private.unmapped');
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => User::factory()->create());
    expect(fn () => app(EnsurePageAccess::class)->handle($request, fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
