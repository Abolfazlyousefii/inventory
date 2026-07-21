<?php

namespace Tests\Feature;

use App\Models\ModelList; use App\Models\Product; use App\Models\ProductVariant; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Spatie\Permission\Models\Permission; use Spatie\Permission\Models\Role; use Tests\TestCase;

class ProductCatalogPrintLayoutTest extends TestCase
{ use RefreshDatabase;
    public function test_print_view_uses_minimal_table_layout(): void { $this->signIn(); $p=Product::create(['name'=>'Layout product','price'=>1000,'stock'=>1,'is_sellable'=>true]); $m=ModelList::create(['brand'=>'Samsung','model_name'=>'A15']); ProductVariant::create(['product_id'=>$p->id,'model_list_id'=>$m->id,'variant_name'=>'A15 black','sell_price'=>2000,'stock'=>1,'is_active'=>true]); $r=$this->get(route('admin.product-exports.print'))->assertOk(); $r->assertSee('catalog-print-table')->assertSee('catalog-product-row')->assertSee('catalog-variant-row')->assertDontSee('catalog-product card')->assertDontSee('بدون تنوع قابل نمایش')->assertDontSee('meta div'); $r->assertSee('max-width:32px', false)->assertSee('font-size:7.8px', false); }
    private function signIn(): void { $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
}
