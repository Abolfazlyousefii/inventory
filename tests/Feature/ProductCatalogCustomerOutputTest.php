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

class ProductCatalogCustomerOutputTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_default_output_mode(): void { $this->signIn(); $this->get(route('admin.product-exports.index'))->assertOk()->assertSee('کارت‌های کاتالوگ مشتری'); }
    public function test_catalog_renders_product_cards(): void { $this->signIn(); $p=$this->product('گارد مشتری'); $this->variant($p,'A15','مشکی'); $this->get(route('admin.product-exports.data'))->assertOk()->assertSee('catalog-card')->assertSee('گارد مشتری')->assertSee('A15'); }
    public function test_catalog_does_not_render_each_color_as_a_row(): void { $this->signIn(); $p=$this->product('فشرده'); $this->variant($p,'A15','مشکی'); $this->variant($p,'A15','آبی'); $this->get(route('admin.product-exports.data'))->assertOk()->assertSee('مشکی')->assertSee('آبی')->assertDontSee('catalog-variant-row'); }
    public function test_catalog_header_does_not_print_all_selected_model_names(): void { $this->signIn(); $models=[]; $p=$this->product('پرمدل'); for($i=1;$i<=4;$i++){ $m=ModelList::create(['brand'=>'Samsung','model_name'=>'A'.$i]); $models[]=$m->id; $this->variant($p,'A'.$i,'مشکی',1890000,$m); } $this->get(route('admin.product-exports.print',['model_brand'=>'Samsung','model_list_ids'=>$models]))->assertOk()->assertSee('4 مدل')->assertDontSee('A1، A2، A3، A4'); }
    public function test_price_list_renders_one_row_per_model(): void { $this->signIn(); $p=$this->product('لیست'); $this->variant($p,'A15','مشکی'); $this->variant($p,'A15','آبی'); $this->get(route('admin.product-exports.data',['output_mode'=>'price_list']))->assertOk()->assertSee('price-list-row')->assertSeeInOrder(['مشکی، آبی','1,890,000 ریال']); }
    public function test_products_without_price_are_hidden_by_default(): void { $this->signIn(); $p=$this->product('بدون قیمت',null); $this->variant($p,'A15','مشکی',null); $this->get(route('admin.product-exports.data'))->assertOk()->assertDontSee('بدون قیمت'); }
    public function test_products_without_price_can_be_included(): void { $this->signIn(); $p=$this->product('بدون قیمت',null); $this->variant($p,'A15','مشکی',null); $this->get(route('admin.product-exports.data',['include_without_price'=>1]))->assertOk()->assertSee('قیمت ثبت نشده'); }
    public function test_print_catalog_uses_two_column_layout(): void { $this->signIn(); $this->get(route('admin.product-exports.print'))->assertOk()->assertSee('grid-template-columns:repeat(2,minmax(0,1fr))', false); }
    public function test_large_catalog_card_uses_full_width(): void { $this->signIn(); $p=$this->product('واید'); for($i=1;$i<=21;$i++) $this->variant($p,'A'.$i,'مشکی'); $this->get(route('admin.product-exports.print'))->assertOk()->assertSee('catalog-card--wide'); }
    public function test_product_image_is_not_repeated_for_variants(): void { $this->signIn(); $p=$this->product('تصویر',1000,'products/a.jpg'); $this->variant($p,'A15','مشکی'); $this->variant($p,'A15','آبی'); $html=$this->get(route('admin.product-exports.print'))->assertOk()->getContent(); $this->assertSame(1, substr_count($html, route('products.image',$p))); }

    private function signIn(): void { $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
    private function product(string $name, ?int $price=1000, ?string $image=null): Product { return Product::create(['name'=>$name,'price'=>$price,'stock'=>1,'image_path'=>$image,'is_sellable'=>true]); }
    private function variant(Product $product,string $model,string $color,?int $price=1890000,?ModelList $modelList=null): ProductVariant { $modelList ??= ModelList::create(['brand'=>'Samsung','model_name'=>$model]); $colorModel=Color::create(['name'=>$color]); return ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$modelList->id,'color_id'=>$colorModel->id,'variant_name'=>$model,'variety_name'=>$color,'sell_price'=>$price,'stock'=>3,'is_active'=>true,'sales_enabled'=>true]); }
}
