<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Permissions\PermissionManagementService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserPermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::syncToDatabase();
    }

    public function test_page_renders_standard_alias_unknown_and_empty_roles_safely(): void
    {
        $actor = $this->actor(['permissions.view']);
        $admin = User::factory()->create(['name' => 'کاربر ادمین']);
        $sales = User::factory()->create(['name' => 'کاربر فروش']);
        $legacy = User::factory()->create(['name' => 'کاربر قدیمی']);
        $withoutRole = User::factory()->create(['name' => 'کاربر بدون نقش']);
        $inactive = User::factory()->create(['name' => 'کاربر غیرفعال', 'is_active' => false]);

        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        $sales->assignRole(Role::findOrCreate('Sales', 'web'));
        $legacy->assignRole(Role::findOrCreate('LegacyCustomRole', 'web'));

        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $admin->id]))
            ->assertOk()->assertSee('مدیر سیستم')->assertDontSee('TypeError');
        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $sales->id]))
            ->assertOk()->assertSee('فروشنده');
        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $legacy->id]))
            ->assertOk()->assertSee('LegacyCustomRole')->assertSee('قدیمی');
        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $withoutRole->id]))
            ->assertOk()->assertSee('بدون نقش');
        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $inactive->id]))
            ->assertOk()->assertSee('غیرفعال');
    }

    public function test_invalid_user_id_does_not_fall_through_or_return_500(): void
    {
        $actor = $this->actor(['permissions.view']);

        $this->actingAs($actor)
            ->get(route('admin.permissions.index', ['user_id' => 999999]))
            ->assertOk()
            ->assertSee('کاربر درخواستی پیدا نشد');
    }

    public function test_page_role_actor_can_manage_roles_but_cannot_create_direct_permissions(): void
    {
        $actor = $this->actor(['permissions.view']);
        $target = User::factory()->create();
        $role = Role::findOrCreate('Sales', 'web');

        $this->actingAs($actor)->get(route('admin.permissions.index', ['user_id' => $target->id]))
            ->assertOk()
            ->assertSee('ذخیره نقش‌های کاربر');

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 1,
            'roles_submitted' => 1,
            'roles' => [$role->name],
            'direct_permissions_changed' => 1,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => ['products.view'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole($role));
        $this->assertFalse($target->fresh()->permissions()->where('key', 'products.view')->exists());
    }

    public function test_actor_without_view_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.permissions.index'))
            ->assertForbidden();
    }

    public function test_actor_without_assign_roles_cannot_change_roles_with_forged_input(): void
    {
        $actor = User::factory()->create();
        $actor->permissions()->sync([$this->permissionId('permissions.view'), $this->permissionId('permissions.edit')]);
        $target = User::factory()->create();
        $original = Role::findOrCreate('Sales', 'web');
        $forged = Role::findOrCreate('Admin', 'web');
        $target->assignRole($original);

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 1,
            'direct_permissions_changed' => 0,
            'roles_submitted' => 1,
            'roles' => [$forged->name],
            'direct_permissions_submitted' => 1,
            'direct_permissions' => [],
        ])->assertForbidden();

        $this->assertTrue($target->fresh()->hasRole($original));
        $this->assertFalse($target->fresh()->hasRole($forged));
    }

    public function test_assign_roles_actor_can_remove_all_non_super_admin_roles(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate('Sales', 'web'));

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 1,
            'direct_permissions_changed' => 0,
            'roles_submitted' => 1,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => [],
        ])->assertRedirect();

        $this->assertCount(0, $target->fresh()->roles);
    }

    public function test_last_super_admin_is_protected_for_every_alias_but_one_of_two_can_be_removed(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $payload = [
            'user_id' => $first->id,
            'roles_changed' => 1,
            'direct_permissions_changed' => 0,
            'roles_submitted' => 1,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => [],
        ];

        $this->actingAs($actor)->from(route('admin.permissions.index', ['user_id' => $first->id]))
            ->put(route('admin.permissions.update', $first), $payload)
            ->assertSessionHasErrors('roles');
        $this->assertTrue($first->fresh()->hasRole('Super Admin'));

        $second->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($actor)->put(route('admin.permissions.update', $first), $payload)->assertRedirect();
        $this->assertFalse($first->fresh()->hasRole('Super Admin'));
    }

    public function test_forged_direct_permission_changes_are_ignored_and_legacy_values_are_preserved(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit']);
        $target = User::factory()->create();
        $target->permissions()->attach($this->legacyPermissionId('legacy.keep.readonly'));

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 0,
            'direct_permissions_changed' => 1,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => ['products.edit'],
        ])->assertRedirect();

        expect($target->fresh()->permissions()->pluck('key')->all())
            ->toBe(['legacy.keep.readonly']);

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 0,
            'direct_permissions_changed' => 1,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => [],
        ])->assertRedirect();
        expect($target->fresh()->permissions()->pluck('key')->all())
            ->toBe(['legacy.keep.readonly']);
    }

    public function test_effective_sources_distinguish_role_direct_both_and_none(): void
    {
        $target = User::factory()->create();
        $role = Role::findOrCreate('SourceRole', 'web');
        $this->attachRolePermission($role, 'products.view');
        $this->attachRolePermission($role, 'products.show');
        $target->assignRole($role);
        $target->permissions()->attach($this->permissionId('products.show'));
        $target->permissions()->attach($this->permissionId('customers.view'));

        $effective = app(PermissionManagementService::class)->effective($target);

        expect($effective['products.view']['source'])->toBe('role')
            ->and($effective['customers.view']['source'])->toBe('direct')
            ->and($effective['products.show']['source'])->toBe('both')
            ->and($effective['products.delete']['source'])->toBe('none');
    }

    public function test_get_page_does_not_write_or_sync_catalog(): void
    {
        $actor = $this->actor(['permissions.view']);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql))) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($actor)->get(route('admin.permissions.index'))->assertOk();

        $this->assertSame([], $writes);
    }

    public function test_permissions_sync_command_does_not_change_assignments(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('CommandRole', 'web');
        $this->attachRolePermission($role, 'products.view');
        $user->assignRole($role);
        $user->permissions()->attach($this->permissionId('customers.view'));
        $beforeRoles = DB::table('model_has_roles')->count();
        $beforeDirect = DB::table('user_permissions')->count();

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertSame($beforeRoles, DB::table('model_has_roles')->count());
        $this->assertSame($beforeDirect, DB::table('user_permissions')->count());
    }

    public function test_unchanged_submission_does_not_create_activity_log(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit']);
        $target = User::factory()->create();

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 0,
            'direct_permissions_changed' => 0,
            'direct_permissions_submitted' => 1,
            'direct_permissions' => [],
        ])->assertSessionHas('success', 'تغییری برای ذخیره وجود نداشت.');

        $this->assertSame(0, ActivityLog::query()->where('action', 'permissions.updated')->count());
    }

    public function test_role_only_update_preserves_active_and_legacy_direct_permissions(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate('Sales', 'web'));
        $newRole = Role::findOrCreate('Manager', 'web');
        $target->permissions()->attach([$this->permissionId('products.view'), $this->legacyPermissionId('legacy.direct.one'), $this->legacyPermissionId('legacy.direct.two')]);

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_submitted' => 1,
            'roles_changed' => 1,
            'roles' => [$newRole->name],
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 0,
            'direct_permissions' => ['forged.legacy.value'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasRole('Manager'));
        expect($fresh->permissions()->pluck('key')->all())
            ->toContain('products.view', 'legacy.direct.one', 'legacy.direct.two');
    }

    public function test_role_with_legacy_permission_can_be_assigned(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $target = User::factory()->create();
        $role = Role::findOrCreate('LegacyPermissionRole', 'web');
        DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $this->legacyPermissionId('legacy.inside.role')]);

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_submitted' => 1,
            'roles_changed' => 1,
            'roles' => [$role->name],
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole($role));
    }

    public function test_forged_active_direct_update_preserves_roles_and_legacy_direct_permissions(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit']);
        $target = User::factory()->create();
        $role = Role::findOrCreate('Sales', 'web');
        $target->assignRole($role);
        $target->permissions()->attach($this->legacyPermissionId('legacy.keep.me'));

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_changed' => 0,
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 1,
            'direct_permissions' => ['customers.view', 'legacy.new.cannot'],
        ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasRole($role));
        expect($fresh->permissions()->pluck('key')->all())
            ->toContain('legacy.keep.me')
            ->not->toContain('customers.view')
            ->not->toContain('legacy.new.cannot');
    }

    public function test_unknown_direct_permission_is_ignored_without_blocking_safe_changes(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $target = User::factory()->create();
        $role = Role::findOrCreate('Sales', 'web');

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'roles_submitted' => 1,
            'roles_changed' => 1,
            'roles' => [$role->name],
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 0,
            'direct_permissions' => ['unknown.permission'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->from(route('admin.permissions.index', ['user_id' => $target->id]))
            ->put(route('admin.permissions.update', $target), [
                'user_id' => $target->id,
                'permission_catalog_version' => PermissionCatalog::versionHash(),
                'roles_changed' => 0,
                'direct_permissions_submitted' => 1,
                'direct_permissions_changed' => 1,
                'direct_permissions' => ['unknown.permission'],
            ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'تعدادی دسترسی قدیمی از فرم کنار گذاشته شد و سایر تغییرات ذخیره شدند.');

        $this->assertFalse($target->fresh()->permissions()->where('key', 'unknown.permission')->exists());
    }

    public function test_stale_catalog_version_does_not_create_direct_permissions_and_returns_warning(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit']);
        $target = User::factory()->create();

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'permission_catalog_version' => str_repeat('0', 64),
            'roles_changed' => 0,
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 1,
            'direct_permissions' => ['customers.view', 'stale.cached.permission'],
        ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'فهرست دسترسی‌ها پس از بازشدن صفحه به‌روزرسانی شده بود؛ موارد قدیمی کنار گذاشته شدند.');

        expect($target->fresh()->permissions()->pluck('key')->all())->not->toContain('customers.view')
            ->not->toContain('stale.cached.permission');
    }

    public function test_missing_active_permission_is_not_self_healed_by_ignored_direct_assignment(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit']);
        $target = User::factory()->create();
        DB::table('permissions')->where('key', 'products.edit')->delete();
        $this->assertContains('products.edit', PermissionCatalog::missingActiveKeys());

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'permission_catalog_version' => PermissionCatalog::versionHash(),
            'roles_changed' => 0,
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 1,
            'direct_permissions' => ['products.edit'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertContains('products.edit', PermissionCatalog::missingActiveKeys());
        expect($target->fresh()->permissions()->pluck('key')->all())
            ->not->toContain('products.edit', 'products.show', 'products.view');
    }

    public function test_role_only_update_does_not_self_heal_missing_direct_permission(): void
    {
        $actor = $this->actor(['permissions.view', 'permissions.edit', 'permissions.assign_roles']);
        $target = User::factory()->create();
        $role = Role::findOrCreate('Sales', 'web');
        DB::table('permissions')->where('key', 'products.export')->delete();

        $this->actingAs($actor)->put(route('admin.permissions.update', $target), [
            'user_id' => $target->id,
            'permission_catalog_version' => PermissionCatalog::versionHash(),
            'roles_submitted' => 1,
            'roles_changed' => 1,
            'roles' => [$role->name],
            'direct_permissions_submitted' => 1,
            'direct_permissions_changed' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole($role));
        $this->assertContains('products.export', PermissionCatalog::missingActiveKeys());
    }

    public function test_get_page_reports_missing_permissions_without_writing(): void
    {
        $actor = $this->actor(['permissions.view']);
        DB::table('permissions')->where('key', 'products.export')->delete();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql))) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($actor)->get(route('admin.permissions.index'))
            ->assertOk()->assertSee('فهرست دسترسی‌های پایگاه داده با نسخه نرم‌افزار هماهنگ نیست.');

        $this->assertSame([], $writes);
        $this->assertContains('products.export', PermissionCatalog::missingActiveKeys());
    }

    public function test_catalog_sync_is_idempotent_and_repairs_the_real_guard(): void
    {
        DB::table('permissions')->where('key', 'products.export')->update(['guard_name' => 'stale-guard']);
        $this->assertContains('products.export', PermissionCatalog::missingActiveKeys());

        $first = PermissionCatalog::syncToDatabase();
        $second = PermissionCatalog::syncToDatabase();

        $this->assertSame([], PermissionCatalog::missingActiveKeys());
        $this->assertSame('web', DB::table('permissions')->where('key', 'products.export')->value('guard_name'));
        $this->assertGreaterThanOrEqual(1, $first['updated']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, DB::table('permissions')->where('key', 'products.export')->count());
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $user->permissions()->sync(collect($permissions)->map(fn (string $key): int => $this->permissionId($key))->all());
        $role = Role::findOrCreate('PermissionPageManager', 'web');
        $pagePermission = Permission::findOrCreate('page.roles', 'web');
        $pagePermission->forceFill(['key' => 'page.roles'])->save();
        $role->givePermissionTo($pagePermission);
        $user->assignRole($role);
        return $user;
    }

    private function permissionId(string $key): int
    {
        return (int) DB::table('permissions')->where('key', $key)->value('id');
    }

    private function attachRolePermission(Role $role, string $key): void
    {
        DB::table('role_has_permissions')->updateOrInsert([
            'role_id' => $role->id,
            'permission_id' => $this->permissionId($key),
        ]);
    }

    private function legacyPermissionId(string $key): int
    {
        return (int) DB::table('permissions')->insertGetId([
            'name' => $key,
            'key' => $key,
            'group' => 'legacy',
            'guard_name' => PermissionCatalog::guardName(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
