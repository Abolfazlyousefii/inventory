<?php

use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('separates product definition from ordinary product viewing', function () {
    $reader = User::factory()->create();
    $id = DB::table('permissions')->where('key', 'page.products')->value('id');
    $reader->permissions()->attach($id);
    expect(PageAccessCatalog::userCanRoute($reader, 'products.index'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($reader, 'products.variants'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($reader, 'products.create'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($reader, 'products.store'))->toBeFalse()
        ->and(PermissionCatalog::userHasPermission($reader, 'products.create'))->toBeFalse();

    $creator = User::factory()->create();
    $creator->permissions()->attach(DB::table('permissions')->where('key', 'page.products.definition')->value('id'));
    foreach (['products.create', 'products.store', 'categories.quickStore', 'model-lists.quick-store', 'products.bundle.search'] as $route) {
        expect(PageAccessCatalog::userCanRoute($creator, $route), $route)->toBeTrue();
    }
    expect(PageAccessCatalog::userCanRoute($creator, 'purchases.store'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($creator, 'stock-count-documents.finalize'))->toBeFalse()
        ->and(PermissionCatalog::userHasPermission($creator, 'products.create'))->toBeTrue();
});

it('keeps administrator aliases able to define products', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Admin', 'web'));
    expect(PageAccessCatalog::userCanRoute($user, 'products.create'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($user, 'products.store'))->toBeTrue();
});
