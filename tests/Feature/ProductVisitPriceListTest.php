<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductVisitPriceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_mode_renders_each_variant_as_its_own_row_with_stock_and_price(): void
    {
        $this->signIn();
        $product = $this->product('گارد تست ویزیتوری', 900000);
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone 15']);
        $black = Color::create(['name' => 'مشکی', 'code' => 'VIS-BLACK']);
        $blue = Color::create(['name' => 'آبی', 'code' => 'VIS-BLUE']);

        $this->variant($product, $model, $black, 'طرح مشکی', 'VAR-BLK', 7, 1200000);
        $this->variant($product, $model, $blue, 'طرح آبی', 'VAR-BLU', 3, 1350000);

        $response = $this->get(route('admin.product-exports.print', ['output_mode' => 'visit']))
            ->assertOk()
            ->assertSee('گارد تست ویزیتوری')
            ->assertSee('VAR-BLK')
            ->assertSee('VAR-BLU')
            ->assertSee('1,200,000 ریال')
            ->assertSee('1,350,000 ریال');

        $this->assertSame(1, substr_count($response->getContent(), 'VAR-BLK'));
        $this->assertSame(1, substr_count($response->getContent(), 'VAR-BLU'));
    }

    public function test_visit_stock_filter_applies_to_variant_rows_not_only_the_parent_product(): void
    {
        $this->signIn();
        $product = $this->product('محصول موجودی ترکیبی');
        $model = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A55']);
        $color = Color::create(['name' => 'مشکی', 'code' => 'VIS-STOCK-BLACK']);

        $this->variant($product, $model, $color, 'موجود', 'IN-STOCK', 5, 1000000);
        $this->variant($product, $model, $color, 'ناموجود', 'OUT-STOCK', 0, 1000000);

        $this->get(route('admin.product-exports.print', ['output_mode' => 'visit', 'stock_status' => 'in_stock']))
            ->assertOk()
            ->assertSee('IN-STOCK')
            ->assertDontSee('OUT-STOCK');

        $this->get(route('admin.product-exports.print', ['output_mode' => 'visit', 'stock_status' => 'out_of_stock']))
            ->assertOk()
            ->assertSee('OUT-STOCK')
            ->assertDontSee('IN-STOCK');
    }

    public function test_visit_mode_uses_parent_price_as_variant_fallback_and_preserves_zero_stock_label(): void
    {
        $this->signIn();
        $product = $this->product('محصول قیمت مادر', 880000);
        $model = ModelList::create(['brand' => 'Xiaomi', 'model_name' => 'Redmi']);
        $color = Color::create(['name' => 'سفید', 'code' => 'VIS-WHITE']);
        $this->variant($product, $model, $color, 'بدون قیمت مستقل', 'PARENT-PRICE', 0, 0);

        $this->get(route('admin.product-exports.print', ['output_mode' => 'visit', 'stock_status' => 'all']))
            ->assertOk()
            ->assertSee('PARENT-PRICE')
            ->assertSee('880,000 ریال')
            ->assertSee('ناموجود');
    }

    public function test_catalog_mode_keeps_the_existing_grouped_view(): void
    {
        $this->signIn();
        $product = $this->product('محصول کاتالوگی');
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone 14']);
        $color = Color::create(['name' => 'مشکی', 'code' => 'VIS-CATALOG-BLACK']);
        $this->variant($product, $model, $color, 'کاتالوگ', 'CAT-1', 2, 1000000);

        $this->get(route('admin.product-exports.print', ['output_mode' => 'catalog']))
            ->assertOk()
            ->assertSee('catalog-print-table', false)
            ->assertSee('مدل‌های سازگار')
            ->assertDontSee('کد تنوع');
    }

    public function test_simple_product_without_variants_still_has_one_visit_row(): void
    {
        $this->signIn();
        $product = $this->product('محصول ساده', 450000);
        $product->update(['stock' => 9, 'code' => 'SIMPLE-1']);

        $this->get(route('admin.product-exports.print', ['output_mode' => 'visit']))
            ->assertOk()
            ->assertSee('محصول ساده')
            ->assertSee('مدل عمومی')
            ->assertSee('SIMPLE-1')
            ->assertSee('450,000 ریال');
    }

    private function signIn(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\RoutePermissionMiddleware::class);
        $role = Role::findOrCreate('products-viewer', 'web');
        $permission = Permission::findOrCreate('products.view', 'web');
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function product(string $name, int $price = 500000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Visit price list test']);

        return Product::create([
            'name' => $name,
            'sku' => 'VIS-'.uniqid(),
            'category_id' => $category->id,
            'price' => $price,
            'stock' => 1,
            'is_sellable' => true,
        ]);
    }

    private function variant(
        Product $product,
        ModelList $model,
        Color $color,
        string $name,
        string $code,
        int $stock,
        int $price,
    ): ProductVariant {
        return ProductVariant::create([
            'product_id' => $product->id,
            'model_list_id' => $model->id,
            'color_id' => $color->id,
            'variant_name' => $name,
            'variant_code' => $code,
            'sell_price' => $price,
            'stock' => $stock,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
    }
}
