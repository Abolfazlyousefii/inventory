<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Permissions\ScopedPageAccessService;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('removes direct and inherited scoped privileges while preserving unrelated access', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $allowed = User::factory()->create();
    $other = User::factory()->create();
    $role = Role::findOrCreate('SharedAccountant', 'web');
    foreach (['page.sales.preinvoice_finance_review', 'preinvoices.finance.confirm', 'page.sales.invoices', 'invoices.view', 'page.sales.preinvoices'] as $key) {
        DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('key', $key)->value('id')]);
    }
    $allowed->assignRole($role);
    $other->assignRole($role);
    $other->permissions()->attach(DB::table('permissions')->where('key', 'invoices.edit')->value('id'));
    $grants = ['sales.preinvoice_finance_review' => [$allowed->id], 'sales.invoices' => [$allowed->id]];
    app(ScopedPageAccessService::class)->replace($grants, $admin);
    app(ScopedPageAccessService::class)->replace($grants, $admin);
    $allowed = $allowed->fresh();
    $other = $other->fresh();
    foreach (['preinvoice.draft.index', 'preinvoice.draft.finance.update', 'preinvoice.draft.finalize', 'invoices.index', 'invoices.update'] as $route) {
        expect(PageAccessCatalog::userCanRoute($allowed, $route), $route)->toBeTrue()
            ->and(PageAccessCatalog::userCanRoute($other, $route), $route)->toBeFalse()
            ->and(PageAccessCatalog::userCanRoute($admin->fresh(), $route))->toBeTrue();
    }
    expect(PageAccessCatalog::userCanRoute($other, 'preinvoice.create'))->toBeTrue()
        ->and($other->hasRole('SharedAccountant'))->toBeTrue()
        ->and(DB::table('activity_logs')->where('action', 'permissions.scoped_replaced')->count())->toBe(2);
});

it('rejects an invalid plan before changing any permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $before = DB::table('role_has_permissions')->count();
    expect(fn () => app(ScopedPageAccessService::class)->replace(['sales.invoices' => [999999]], $admin))->toThrow(InvalidArgumentException::class);
    expect(DB::table('role_has_permissions')->count())->toBe($before);
});

it('grants warehouse operations only to the selected warehouse staff', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $staff = User::factory()->create();
    $seller = User::factory()->create();
    $pages = ['products.definition', 'warehouse.purchases', 'warehouse.stocktake', 'warehouse.inbound_queue', 'warehouse.reservations'];
    $grants = array_fill_keys($pages, [$staff->id]);
    app(ScopedPageAccessService::class)->replace($grants, $admin);
    foreach (['products.create', 'products.store', 'purchases.index', 'purchases.store', 'stock-count-documents.finalize', 'warehouse.inbound.receive', 'warehouse-reservations.index'] as $route) {
        expect(app('router')->getRoutes()->getByName($route), $route)->not->toBeNull();
        expect(PageAccessCatalog::userCanRoute($staff->fresh(), $route), $route)->toBeTrue()
            ->and(PageAccessCatalog::userCanRoute($seller->fresh(), $route), $route)->toBeFalse();
    }
});

it('rolls the entire access change back if its audit cannot be saved', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $user = User::factory()->create();
    $user->permissions()->attach(DB::table('permissions')->where('key', 'page.sales.invoices')->value('id'));
    $before = DB::table('user_permissions')->orderBy('user_id')->get()->toJson();
    ActivityLog::creating(function () {
        throw new RuntimeException('audit unavailable');
    });
    try {
        expect(fn () => app(ScopedPageAccessService::class)->replace(['sales.invoices' => []], $admin))->toThrow(RuntimeException::class, 'audit unavailable');
        expect(DB::table('user_permissions')->orderBy('user_id')->get()->toJson())->toBe($before)
            ->and(PageAccessCatalog::userCanRoute($user->fresh(), 'invoices.index'))->toBeTrue();
    } finally {
        ActivityLog::flushEventListeners();
    }
});
