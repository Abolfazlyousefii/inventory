<?php

namespace Tests\Feature;

use App\Models\ModelList;
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

    public function test_product_with_active_variants_displays_variants(): void
    {
        $this->signIn(); $product=$this->product('Variant product'); $model=ModelList::create(['brand'=>'Samsung','model_name'=>'A15']);
        foreach(['A15 مشکی','A15 آبی','A15 سفید'] as $name) $this->variant($product,$model,$name);
        $this->get(route('admin.product-exports.index'))->assertOk()->assertSee('A15 مشکی')->assertSee('A15 آبی')->assertSee('A15 سفید')->assertDontSee('بدون تنوع قابل نمایش');
    }

    public function test_print_displays_product_variants(): void
    {
        $this->signIn(); $product=$this->product('Print variants'); $model=ModelList::create(['brand'=>'Samsung','model_name'=>'A25']); $this->variant($product,$model,'Print A25');
        $this->get(route('admin.product-exports.print'))->assertOk()->assertSee('Print variants')->assertSee('Print A25');
    }

    public function test_different_products_do_not_share_variants(): void
    {
        $this->signIn(); $model=ModelList::create(['brand'=>'Samsung','model_name'=>'A35']); $a=$this->product('Product A'); $b=$this->product('Product B'); $this->variant($a,$model,'Only A'); $this->variant($b,$model,'Only B');
        $html=$this->get(route('admin.product-exports.data'))->assertOk()->getContent();
        $this->assertStringContainsString('Product A', $html); $this->assertStringContainsString('Only A', $html); $this->assertStringContainsString('Product B', $html); $this->assertStringContainsString('Only B', $html);
    }

    public function test_inactive_variant_is_excluded(): void
    {
        $this->signIn(); $product=$this->product('Active only'); $model=ModelList::create(['brand'=>'Samsung','model_name'=>'A55']); $this->variant($product,$model,'Visible'); $this->variant($product,$model,'Hidden',3,2000,false);
        $this->get(route('admin.product-exports.data'))->assertOk()->assertSee('Visible')->assertDontSee('Hidden');
    }

    public function test_catalog_query_does_not_use_parameterized_valid_variants_relation(): void
    {
        $service=file_get_contents(app_path('Services/ProductExportService.php'));
        $this->assertStringNotContainsString('validVariants', $service);
    }

    private function signIn(): void { $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
    private function product(string $name): Product { return Product::create(['name'=>$name,'price'=>1000,'stock'=>1,'is_sellable'=>true]); }
    private function variant(Product $product, ModelList $model, string $name, int $stock=3, int $price=2000, bool $active=true): ProductVariant { return ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$model->id,'variant_name'=>$name,'sell_price'=>$price,'stock'=>$stock,'is_active'=>$active,'sales_enabled'=>false]); }
}
