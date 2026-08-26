<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminRoleAssetPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::syncToDatabase();
        $this->artisan('permissions:sync')->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_role_form_displays_asset_action_permissions(): void
    {
        $role = Role::findOrCreate('StorageManager', 'web');

        $this->actingAs($this->owner())
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('عملیات امین اموال')
            ->assertSee('ایجاد سند اموال')
            ->assertSee('ویرایش سند اموال')
            ->assertSee('نهایی‌سازی سند اموال')
            ->assertSee('لغو سند اموال')
            ->assertSee('چاپ/دانلود سند اموال')
            ->assertSee('جستجوی کد اموال')
            ->assertSee('assets.documents.create')
            ->assertSee('assets.documents.edit')
            ->assertSee('assets.documents.confirm')
            ->assertSee('assets.documents.cancel')
            ->assertSee('assets.documents.print')
            ->assertSee('assets.codes.search');
    }

    public function test_role_update_stores_asset_action_permissions_and_preserves_unmanaged_permissions(): void
    {
        $role = Role::findOrCreate('AssetActionManagedRole', 'web');
        $hiddenPermission = $this->permission('sales_returns.override_destination');
        $role->givePermissionTo($hiddenPermission);

        $submitted = collect([
            'page.assets',
            'assets.documents.create',
            'assets.documents.edit',
            'assets.documents.cancel',
            'assets.documents.print',
            'assets.codes.search',
        ])->map(fn (string $key) => $this->permission($key)->id)->values()->all();

        $this->actingAs($this->owner())
            ->put(route('admin.roles.update', $role), [
                'name' => $role->name,
                'permissions' => $submitted,
            ])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();

        $keys = $role->fresh()->permissions()->pluck('key')->values()->all();

        $this->assertContains('page.assets', $keys);
        $this->assertContains('assets.documents.create', $keys);
        $this->assertContains('assets.documents.edit', $keys);
        $this->assertContains('assets.documents.cancel', $keys);
        $this->assertContains('assets.documents.print', $keys);
        $this->assertContains('assets.codes.search', $keys);
        $this->assertContains('sales_returns.override_destination', $keys, 'Unmanaged hidden permissions must be preserved.');
        $this->assertNotContains('assets.documents.confirm', $keys, 'Unsubmitted managed asset action permissions must be removed.');
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        return $user;
    }

    private function permission(string $key): Permission
    {
        return Permission::query()->where('key', $key)->firstOrFail();
    }
}
