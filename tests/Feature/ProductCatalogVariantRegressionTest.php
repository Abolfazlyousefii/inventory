<?php

namespace Tests\Feature;

use App\Models\ModelList;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCatalogVariantRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_accepts_has_many_relation(): void
    {
        $this->signIn();
        $product = $this->product('Variant product');
        $model = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A15']);

        $this->variant($product, $model, 'A15 مشکی');
        $this->variant($product, $model, 'A15 آبی');

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertSee('Variant product')
            ->assertSee('A15')
            ->assertDontSee('بدون تنوع قابل نمایش');
    }

    public function test_print_accepts_has_many_relation(): void
    {
        $this->signIn();
        $product = $this->product('Print variants');
        $model = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A25']);

        $this->variant($product, $model, 'Print A25 Black');
        $this->variant($product, $model, 'Print A25 Blue');

        $this->get(route('admin.product-exports.print'))
            ->assertRedirect(route('admin.product-exports.download', ['stock_status' => 'all', 'include_without_price' => 0]));
    }

    public function test_model_list_filter_still_works(): void
    {
        $this->signIn();
        $product = $this->product('Filtered model product');
        $includedModel = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A35']);
        $excludedModel = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A55']);

        $this->variant($product, $includedModel, 'Selected model variant');
        $this->variant($product, $excludedModel, 'Other model variant');

        $this->get(route('admin.product-exports.index', [
            'model_brand' => 'Samsung',
            'model_list_ids' => [$includedModel->id],
        ]))
            ->assertOk()
            ->assertSee('Filtered model product')
            ->assertSee('A35')
            ->assertDontSee('Other model variant');
    }

    public function test_inactive_variant_is_excluded(): void
    {
        $this->signIn();
        $product = $this->product('Active only');
        $model = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A55']);

        $this->variant($product, $model, 'Visible variant');
        $this->variant($product, $model, 'Hidden variant', 3, 2000, false);

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertSee('Active only')
            ->assertSee('A55')
            ->assertDontSee('Hidden variant');
    }

    public function test_catalog_query_does_not_use_parameterized_valid_variants_relation(): void
    {
        $service=file_get_contents(app_path('Services/ProductExportService.php'));
        $this->assertStringNotContainsString('validVariants', $service);
    }

    private function signIn(): void { $this->withoutMiddleware(\App\Http\Middleware\RoutePermissionMiddleware::class); $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
    private function product(string $name): Product { $category=Category::firstOrCreate(['name'=>'Variant regression test']); return Product::create(['name'=>$name,'sku'=>'VR-'.uniqid(),'category_id'=>$category->id,'price'=>1000,'stock'=>1,'is_sellable'=>true]); }
    private function variant(Product $product, ModelList $model, string $name, int $stock=3, int $price=2000, bool $active=true): ProductVariant { return ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$model->id,'variant_name'=>$name,'sell_price'=>$price,'stock'=>$stock,'is_active'=>$active,'sales_enabled'=>false]); }
}
