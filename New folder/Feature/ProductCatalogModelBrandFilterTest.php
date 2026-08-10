<?php

namespace Tests\Feature;

use App\Models\Category; use App\Models\ModelList; use App\Models\Product; use App\Models\ProductVariant; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Spatie\Permission\Models\Permission; use Spatie\Permission\Models\Role; use Tests\TestCase;

class ProductCatalogModelBrandFilterTest extends TestCase
{ use RefreshDatabase;
    public function test_model_brand_filter_lists_distinct_brands(): void { $this->signIn(); ModelList::create(['brand'=>'Samsung','model_name'=>'A15']); ModelList::create(['brand'=>'Samsung','model_name'=>'A25']); ModelList::create(['brand'=>'Xiaomi','model_name'=>'Redmi']); $this->get(route('admin.product-exports.index'))->assertOk()->assertSee('Samsung')->assertSee('Xiaomi'); }
    public function test_model_list_endpoint_only_returns_selected_brand_models(): void { $this->signIn(); [$s,$x]=$this->models(); $p=$this->product('P'); $this->variant($p,$s,'S'); $this->variant($p,$x,'X'); $this->getJson(route('admin.product-exports.model-lists',['brand'=>'Samsung']))->assertOk()->assertJsonFragment(['id'=>$s->id,'name'=>'A15'])->assertJsonMissing(['id'=>$x->id]); }
    public function test_one_model_checkbox_filters_products(): void { $this->signIn(); [$a,$b]=$this->models('Samsung'); $p=$this->product('One model'); $this->variant($p,$a,'A15 only'); $this->variant($p,$b,'A25 hidden'); $this->get(route('admin.product-exports.data',['model_brand'=>'Samsung','model_list_ids'=>[$a->id]]))->assertOk()->assertSee('A15')->assertDontSee('A25'); }
    public function test_multiple_model_checkboxes_use_or_logic(): void { $this->signIn(); [$a,$b]=$this->models('Samsung'); $p=$this->product('OR model'); $this->variant($p,$a,'A15'); $this->variant($p,$b,'A25'); $this->get(route('admin.product-exports.data',['model_brand'=>'Samsung','model_list_ids'=>[$a->id,$b->id]]))->assertOk()->assertSee('A15')->assertSee('A25'); }
    public function test_mixed_brand_model_ids_are_rejected(): void { $this->signIn(); [$s,$x]=$this->models(); $this->from(route('admin.product-exports.index'))->get(route('admin.product-exports.index',['model_brand'=>'Samsung','model_list_ids'=>[$s->id,$x->id]]))->assertSessionHasErrors('model_list_ids'); }
    public function test_selected_checkboxes_are_restored_after_refresh(): void { $this->signIn(); [$s,]=$this->models(); $p=$this->product('Restore'); $this->variant($p,$s,'Restore A15'); $this->get(route('admin.product-exports.index',['model_brand'=>'Samsung','model_list_ids'=>[$s->id]]))->assertOk()->assertSee('data-selected-models')->assertSee((string)$s->id); }
    public function test_changing_brand_clears_old_model_ids(): void { $this->assertStringContainsString('selected.clear()', file_get_contents(resource_path('views/product-exports/index.blade.php'))); }
    private function signIn(): void { $this->withoutMiddleware(\App\Http\Middleware\RoutePermissionMiddleware::class); $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
    private function models(string $brand='mixed'): array { return $brand==='mixed' ? [ModelList::create(['brand'=>'Samsung','model_name'=>'A15']),ModelList::create(['brand'=>'Xiaomi','model_name'=>'Redmi'])] : [ModelList::create(['brand'=>$brand,'model_name'=>'A15']),ModelList::create(['brand'=>$brand,'model_name'=>'A25'])]; }
    private function product(string $name): Product { $category=Category::firstOrCreate(['name'=>'Model brand test']); return Product::create(['name'=>$name,'sku'=>'MB-'.uniqid(),'category_id'=>$category->id,'price'=>1000,'stock'=>1,'is_sellable'=>true]); }
    private function variant(Product $p, ModelList $m, string $n): ProductVariant { return ProductVariant::create(['product_id'=>$p->id,'model_list_id'=>$m->id,'variant_name'=>$n,'sell_price'=>2000,'stock'=>1,'is_active'=>true,'sales_enabled'=>false]); }
}
