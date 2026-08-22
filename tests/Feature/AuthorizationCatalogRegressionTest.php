<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationCatalogRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::syncToDatabase();
    }

    public function test_permissions_sync_creates_every_page_permission_and_is_idempotent(): void
    {
        DB::table('permissions')->where('key', 'like', 'page.%')->delete();

        $this->artisan('permissions:sync')->assertSuccessful();
        foreach (['page.sales.returns', 'page.commercial.invoice_reassignments', 'page.warehouse.inbound_queue', 'page.commercial.commissions', 'page.roles'] as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key, 'guard_name' => 'web']);
        }
        $expected = count(PageAccessCatalog::pages());
        $this->assertSame($expected, DB::table('permissions')->where('key', 'like', 'page.%')->count());

        $this->artisan('permissions:sync')->assertSuccessful();
        $this->assertSame($expected, DB::table('permissions')->where('key', 'like', 'page.%')->count());
        $this->assertSame(1, DB::table('permissions')->where('key', 'page.sales.returns')->count());
    }

    public function test_role_form_is_catalog_driven_and_contains_required_pages(): void
    {
        $role = Role::findOrCreate('CatalogRole', 'web');
        $this->actingAs($this->owner())->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('page.sales.returns')
            ->assertSee('برگشت از فروش')
            ->assertSee('page.commercial.invoice_reassignments')
            ->assertSee('انتقال فروشنده فاکتور')
            ->assertSee('page.warehouse.inbound_queue')
            ->assertSee('صف ورودی موجودی');
    }

    public function test_role_save_preserves_hidden_permission_while_managed_page_can_be_removed(): void
    {
        $role = Role::findOrCreate('PreserveHiddenRole', 'web');
        $salesReturn = $this->permission('page.sales.returns');
        $inbound = $this->permission('page.warehouse.inbound_queue');
        $hidden = $this->permission('sales_returns.override_destination');
        $role->givePermissionTo([$salesReturn, $inbound, $hidden]);

        $this->actingAs($this->owner())->put(route('admin.roles.update', $role), [
            'name' => $role->name,
            'permissions' => [$inbound->id],
        ])->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();

        $keys = $role->fresh()->permissions()->pluck('key')->all();
        $this->assertContains('sales_returns.override_destination', $keys);
        $this->assertContains('page.warehouse.inbound_queue', $keys);
        $this->assertNotContains('page.sales.returns', $keys);
    }

    public function test_sales_return_page_routes_allow_role_permission_and_deny_missing_permission(): void
    {
        $denied = User::factory()->create();
        $this->actingAs($denied)->get(route('vouchers.return-from-sale.index'))->assertForbidden();
        $this->actingAs($denied)->get(route('vouchers.return-from-sale.create'))->assertForbidden();

        $allowed = $this->pageUser('page.sales.returns');
        $this->actingAs($allowed)->get(route('vouchers.return-from-sale.index'))->assertOk();
        $this->actingAs($allowed)->get(route('vouchers.return-from-sale.create'))->assertOk();

        foreach (PageAccessCatalog::page('sales.returns')['routes'] as $routeName) {
            $this->assertContains('page.sales.returns', PageAccessCatalog::permissionsForRoute($routeName));
        }
    }

    public function test_sales_return_sensitive_destination_override_remains_independent(): void
    {
        $user = $this->pageUser('page.sales.returns');
        $this->assertFalse($user->can('sales_returns.override_destination'));
        $this->assertFalse(PermissionCatalog::userHasPermission($user, 'sales_returns.override_destination'));

        $central = Warehouse::query()->firstOrCreate(['type' => 'central', 'name' => 'انبار مرکزی'], ['is_active' => true]);
        $return = Warehouse::query()->firstOrCreate(['type' => 'return', 'name' => 'انبار مرجوعی'], ['is_active' => true]);
        $service = app(SalesReturnService::class);
        $this->assertSame($central->id, $service->resolveDestinationWarehouse('healthy', $return->id, false)->id);

        $user->roles()->firstOrFail()->givePermissionTo($this->permission('sales_returns.override_destination'));
        $user->refresh();
        $this->assertTrue($user->can('sales_returns.override_destination'));
        $this->assertSame($return->id, $service->resolveDestinationWarehouse('healthy', $return->id, true)->id);
    }

    public function test_invoice_reassignment_routes_are_denied_without_and_available_with_page_permission(): void
    {
        $denied = User::factory()->create();
        $this->actingAs($denied)->get(route('commercial.invoice-reassignments.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('commercial.invoice-reassignments.store'), [])->assertForbidden();

        $allowed = $this->pageUser('page.commercial.invoice_reassignments');
        $this->actingAs($allowed)->get(route('commercial.invoice-reassignments.index'))->assertOk();
        $this->actingAs($allowed)->getJson(route('commercial.invoice-reassignments.search'))->assertOk()->assertJson(['data' => []]);
        $this->actingAs($allowed)->postJson(route('commercial.invoice-reassignments.preview'), [])->assertUnprocessable();
        $this->actingAs($allowed)->post(route('commercial.invoice-reassignments.store'), [])->assertSessionHasErrors(['invoice_ids', 'seller_id', 'reason', 'sync_preinvoice', 'preview_token']);
    }

    public function test_owner_bypasses_page_checks(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('vouchers.return-from-sale.index'))->assertOk();
        $this->actingAs($owner)->get(route('commercial.invoice-reassignments.index'))->assertOk();
        $this->actingAs($owner)->get(route('warehouse.inbound.index'))->assertOk();
        $this->actingAs($owner)->get(route('admin.roles.index'))->assertOk();
    }

    public function test_sidebar_visibility_and_sales_return_active_state_follow_page_permissions(): void
    {
        $salesUser = $this->pageUser('page.sales.returns');
        $this->actingAs($salesUser)->get(route('vouchers.return-from-sale.index'))
            ->assertOk()
            ->assertSee('برگشت از فروش')
            ->assertSee('data-initial-open-section="sales"', false)
            ->assertSee('sidebar-sublink active', false)
            ->assertDontSee('انتقال فروشنده فاکتور');

        $transferUser = $this->pageUser('page.commercial.invoice_reassignments');
        $this->actingAs($transferUser)->get(route('commercial.invoice-reassignments.index'))
            ->assertOk()
            ->assertSee('انتقال فروشنده فاکتور')
            ->assertDontSee('برگشت از فروش');
    }

    public function test_missing_active_keys_and_version_hash_include_page_catalog(): void
    {
        $this->assertContains('page.sales.returns', PermissionCatalog::activeKeys());
        DB::table('permissions')->where('key', 'page.sales.returns')->delete();
        $this->assertContains('page.sales.returns', PermissionCatalog::missingActiveKeys());

        $active = PermissionCatalog::activeKeys();
        sort($active, SORT_STRING);
        $this->assertSame(hash('sha256', implode("\n", $active)), PermissionCatalog::versionHash());
        $legacyOnly = array_values(array_filter($active, fn (string $key) => ! str_starts_with($key, 'page.')));
        $this->assertNotSame(hash('sha256', implode("\n", $legacyOnly)), PermissionCatalog::versionHash());
    }

    public function test_critical_named_routes_have_explicit_page_owners(): void
    {
        $criticalPrefixes = [
            'vouchers.return-from-sale.',
            'commercial.invoice-reassignments.',
            'warehouse.inbound.',
            'commercial.commissions.',
            'admin.roles.',
            'admin.permissions.',
        ];
        $routes = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => is_string($name) && collect($criticalPrefixes)->contains(fn ($prefix) => str_starts_with($name, $prefix)));

        $unmapped = $routes->filter(fn (string $name) => PageAccessCatalog::permissionsForRoute($name) === [])->values()->all();
        $this->assertNotEmpty($routes);
        $this->assertSame([], $unmapped, 'Critical named routes without PageAccessCatalog owner: '.implode(', ', $unmapped));
    }

    private function pageUser(string $key): User
    {
        $role = Role::findOrCreate('Role-'.str_replace('.', '-', $key), 'web');
        $role->givePermissionTo($this->permission($key));
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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
