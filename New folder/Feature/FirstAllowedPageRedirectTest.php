<?php

use App\Models\User;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('redirects a user without dashboard to the first allowed catalog page', function () {
    $permissionId = DB::table('permissions')->insertGetId([
        'key'=>'page.products', 'name'=>'page.products', 'group'=>'test', 'guard_name'=>'web',
        'created_at'=>now(), 'updated_at'=>now(),
    ]);
    $role = Role::findOrCreate('Product viewer', 'web');
    DB::table('role_has_permissions')->insert(['role_id'=>$role->id, 'permission_id'=>$permissionId]);
    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(app(FirstAllowedPageResolver::class)->destination($user->fresh()))
        ->toBe(route('products.index'));
});

it('uses a stable no-access page instead of creating a redirect loop', function () {
    $user = User::factory()->create();

    expect(app(FirstAllowedPageResolver::class)->destination($user))
        ->toBe(route('access.unassigned'));

    $this->actingAs($user)->get(route('access.unassigned'))
        ->assertOk()
        ->assertSee('دسترسی برای شما تعریف نشده است');
});
