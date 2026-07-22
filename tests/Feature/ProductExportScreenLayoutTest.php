<?php

namespace Tests\Feature;

use App\Models\Color;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportScreenLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_export_screen_has_professional_layout_contract(): void
    {
        $this->signIn();
        $product = Product::create(['name' => 'گارد تست', 'price' => 1000, 'stock' => 1, 'is_sellable' => true]);
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone 11 Pro']);
        $color = Color::create(['name' => 'مشکی']);
        ProductVariant::create(['product_id' => $product->id, 'model_list_id' => $model->id, 'color_id' => $color->id, 'variant_name' => 'iPhone 11 Pro', 'variety_name' => 'مشکی', 'sell_price' => 1500, 'stock' => 1, 'is_active' => true, 'sales_enabled' => true]);

        $response = $this->get(route('admin.product-exports.index'));
        $response->assertOk()
            ->assertSee('data-product-export-page', false)
            ->assertSee('pe-page-header')
            ->assertSee('pe-filter-panel')
            ->assertSee('pe-model-panel')
            ->assertSee('pe-model-panel__grid')
            ->assertSee('pe-filter-actions')
            ->assertSee('pe-price-table')
            ->assertSee('<col style="width: 28%">', false)
            ->assertSee('<col style="width: 36%">', false)
            ->assertSee('<col style="width: 20%">', false)
            ->assertSee('<col style="width: 16%">', false)
            ->assertSee('دانلود لیست قیمت PDF')
            ->assertSee('class="pe-model-token" dir="ltr"', false)
            ->assertDontSee('output_mode')
            ->assertDontSee('placeholder-product')
            ->assertDontSee('broken');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<thead>'));
        $this->assertSame(1, substr_count($html, 'مدل‌های سازگار</th>'));
        $this->assertSame(1, substr_count($html, 'محصول</th>'));
    }

    public function test_product_export_javascript_contract_exists(): void
    {
        $script = file_get_contents(resource_path('js/product-exports.js'));
        foreach (['updateSelectedCount', 'selectAll', 'clearSelection', 'filterModels', 'hideBrokenImages', 'toggleColors'] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }
    }

    private function signIn(): void
    {
        $role = Role::findOrCreate('products-viewer', 'web');
        $permission = Permission::findOrCreate('products.view', 'web');
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }
}
