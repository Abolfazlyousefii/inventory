<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Crm\CrmRoleMapper;
use App\Services\Permissions\ScopedPageAccessService;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('requires warehouse access for bundle stock mutations while retaining catalogue reads', function () {
    $reader = User::factory()->create();
    $reader->permissions()->attach(DB::table('permissions')->where('key', 'page.products')->value('id'));
    expect(PageAccessCatalog::userCanRoute($reader, 'products.bundle.assemble'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($reader, 'products.bundle.disassemble'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($reader, 'products.variants'))->toBeTrue();
});

it('does not grant financial invoice reapproval through payment entry access', function () {
    $user = User::factory()->create();
    $user->permissions()->attach(DB::table('permissions')->where('key', 'page.finance.payments')->value('id'));
    expect(PageAccessCatalog::userCanRoute($user, 'finance.invoices.reapprove'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($user, 'finance.invoices.return-to-sales'))->toBeFalse();
});

it('removes the selected administrator identity atomically and preserves ordinary roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $target = User::factory()->create();
    $target->assignRole(Role::findOrCreate('Admin', 'web'));
    $ordinary = Role::findOrCreate('OrdinaryStaff', 'web');
    $ordinary->givePermissionTo(Permission::where('key', 'page.products')->firstOrFail());
    $target->assignRole($ordinary);
    app(ScopedPageAccessService::class)->replace(['warehouse.collection' => []], $admin, [$target->id]);
    expect($target->fresh()->isAdministrator())->toBeFalse()
        ->and($target->fresh()->hasRole('OrdinaryStaff'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($target->fresh(), 'products.index'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($target->fresh(), 'vouchers.sales.queue'))->toBeFalse()
        ->and($admin->fresh()->isAdministrator())->toBeTrue();
});

it('grants asset actions and daily warehouse reports to selected staff without exposing them to readers', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $staff = User::factory()->create();
    $other = User::factory()->create();
    app(ScopedPageAccessService::class)->replace(['assets' => [$staff->id], 'warehouse.daily_sales' => [$staff->id]], $admin);
    expect(PageAccessCatalog::userCanRoute($staff->fresh(), 'warehouse.daily-sales.store'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($other->fresh(), 'warehouse.daily-sales.store'))->toBeFalse();
    foreach ([$staff, $admin] as $user) {
        $request = Request::create('/warehouse-assets/create', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $route = app('router')->getRoutes()->getByName('asset.documents.create');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user->fresh());
        expect(app(RoutePermissionMiddleware::class)->handle($request, fn () => response('ok'), 'assets.documents.create')->getStatusCode())->toBe(200);
    }
});

it('does not let an operator remove their own administrator access', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    expect(fn () => app(ScopedPageAccessService::class)->replace(['warehouse.collection' => []], $admin, [$admin->id]))->toThrow(InvalidArgumentException::class);
    expect($admin->fresh()->isAdministrator())->toBeTrue();
});

it('restores the administrator role as well as page grants when the audit fails', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('Admin', 'web'));
    $target = User::factory()->create();
    $target->assignRole(Role::findOrCreate('Admin', 'web'));
    ActivityLog::creating(function () {
        throw new RuntimeException('audit unavailable');
    });
    try {
        expect(fn () => app(ScopedPageAccessService::class)->replace(['warehouse.collection' => []], $actor, [$target->id]))->toThrow(RuntimeException::class);
        expect($target->fresh()->isAdministrator())->toBeTrue();
    } finally {
        ActivityLog::flushEventListeners();
    }
});

it('does not restore a manually removed administrator through a later CRM sync', function () {
    config(['crm.roles.mapping' => ['Admin' => ['Admin'], 'Sales' => ['Sales']], 'crm.roles.managed' => ['Admin', 'Sales']]);
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('Admin', 'web'));
    Role::findOrCreate('Sales', 'web');
    $target = User::factory()->create();
    $target->assignRole(Role::findOrCreate('Admin', 'web'));
    app(ScopedPageAccessService::class)->replace(['warehouse.collection' => []], $actor, [$target->id]);
    app(CrmRoleMapper::class)->sync($target->fresh(), ['Admin', 'Sales']);
    app(CrmRoleMapper::class)->sync($actor->fresh(), ['Admin']);
    expect($target->fresh()->isAdministrator())->toBeFalse()
        ->and($target->fresh()->hasRole('Sales'))->toBeTrue()
        ->and($actor->fresh()->isAdministrator())->toBeTrue();
});
