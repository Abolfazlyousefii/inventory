<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportFilterRequestTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $role = Role::findOrCreate('products-exporter', 'web');
        $permission = Permission::query()->where('key', 'page.products')->first()
            ?? Permission::findOrCreate('page.products', 'web');
        if ($permission->key !== 'page.products') {
            $permission->forceFill(['key' => 'page.products'])->save();
        }
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_index_opens_without_query_string(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertSee('کاتالوگ محصولات')
            ->assertSee('value="all" selected', false)
            ->assertViewHas('filters', fn (array $filters) => $filters['stock_status'] === 'all');
    }

    public function test_output_mode_defaults_to_visit_and_catalog_can_be_selected(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['output_mode'] === 'visit');

        $this->get(route('admin.product-exports.index', ['output_mode' => 'catalog']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['output_mode'] === 'catalog');
    }

    public function test_invalid_output_mode_is_rejected(): void
    {
        $this->signIn();
        $this->mock(ProductExportService::class, fn ($mock) => $mock->shouldNotReceive('paginate'));

        $this->from(route('admin.product-exports.index'))
            ->get(route('admin.product-exports.index', ['output_mode' => 'invalid']))
            ->assertRedirect(route('admin.product-exports.index'))
            ->assertSessionHasErrors('output_mode');
    }

    public function test_empty_stock_status_defaults_to_all(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index', ['stock_status' => '']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['stock_status'] === 'all');
    }

    public function test_in_stock_is_preserved(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index', ['stock_status' => 'in_stock']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['stock_status'] === 'in_stock');
    }

    public function test_out_of_stock_is_preserved(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index', ['stock_status' => 'out_of_stock']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['stock_status'] === 'out_of_stock');
    }

    public function test_invalid_stock_status_is_rejected(): void
    {
        $this->signIn();
        $this->mock(ProductExportService::class, fn ($mock) => $mock->shouldNotReceive('paginate'));

        $this->from(route('admin.product-exports.index'))
            ->get(route('admin.product-exports.index', ['stock_status' => 'invalid']))
            ->assertRedirect(route('admin.product-exports.index'))
            ->assertSessionHasErrors('stock_status');
    }

    public function test_missing_model_list_ids_defaults_to_empty_array(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['model_list_ids'] === []);
    }

    public function test_missing_page_defaults_to_one(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['page'] === 1);
    }
}
