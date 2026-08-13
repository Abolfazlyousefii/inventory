<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductPriceListPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPriceListPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_route_returns_standalone_html_preview_with_manual_print_controls(): void
    {
        $this->signIn();
        $this->product('گارد چاپی');

        $response = $this->get(route('admin.product-exports.print'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('<!doctype html>', false)
            ->assertSee('لیست قیمت و موجودی ویزیتوری')
            ->assertSee('چاپ / ذخیره PDF')
            ->assertSee('window.print()', false)
            ->assertSee('print-toolbar', false)
            ->assertSee('@page { size: A4 landscape;', false)
            ->assertDontSee('window.onload', false);

        $this->assertStringNotContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_legacy_download_redirects_to_print_with_every_filter_and_never_renders_pdf(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'ریشه چاپ']);
        $child = Category::create(['name' => 'زیرشاخه چاپ', 'parent_id' => $root->id]);
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone 15']);
        $product = $this->product('محصول انتخابی چاپ', 1000, null, $child);
        $this->variant($product, 'iPhone 15', 'مشکی', 1200, $model);
        $this->mock(ProductPriceListPdfService::class, fn ($mock) => $mock->shouldNotReceive('render'));

        $query = [
            'root_category_id' => $root->id,
            'subcategory_id' => $child->id,
            'model_brand' => 'Apple',
            'model_list_ids' => [$model->id],
            'product_ids' => [$product->id],
            'stock_status' => 'all',
            'include_without_price' => 1,
            'output_mode' => 'visit',
        ];

        $response = $this->get(route('admin.product-exports.download', $query))->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $redirectedQuery);

        $this->assertStringContainsString('/admin/product-exports/print', $location);
        foreach ($query as $key => $value) {
            $this->assertEquals($value, $redirectedQuery[$key] ?? null, "Missing redirected query parameter: {$key}");
        }
    }

    public function test_legacy_export_redirects_to_print_with_query_parameters(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.export', [
            'stock_status' => 'out_of_stock',
            'output_mode' => 'catalog',
            'include_without_price' => 1,
        ]))->assertRedirect(route('admin.product-exports.print', [
            'stock_status' => 'out_of_stock',
            'output_mode' => 'catalog',
            'include_without_price' => 1,
        ]));
    }

    public function test_index_exposes_print_action_in_a_new_tab_without_download_code(): void
    {
        $this->signIn();

        $this->get(route('admin.product-exports.index'))
            ->assertOk()
            ->assertSee('چاپ لیست')
            ->assertSee('امکان چاپ یا ذخیره به صورت PDF')
            ->assertSee('data-print-url', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('printProductsButton', false)
            ->assertDontSee('data-download-url', false)
            ->assertDontSee('downloadProductsButton', false)
            ->assertDontSee('دانلود لیست قیمت PDF');
    }

    public function test_visit_and_catalog_modes_render_their_print_tables(): void
    {
        $this->signIn();
        $product = $this->product('محصول دوحالته');
        $this->variant($product, 'iPhone 14', 'مشکی');

        $this->get(route('admin.product-exports.print', ['output_mode' => 'visit']))
            ->assertOk()
            ->assertSee('print-output--visit', false)
            ->assertSee('visit-print-table', false)
            ->assertSee('کد تنوع')
            ->assertDontSee('catalog-print-table', false);

        $this->get(route('admin.product-exports.print', ['output_mode' => 'catalog']))
            ->assertOk()
            ->assertSee('print-output--catalog', false)
            ->assertSee('catalog-print-table', false)
            ->assertSee('مدل‌های سازگار')
            ->assertDontSee('visit-print-table', false);
    }

    public function test_product_without_image_has_no_placeholder_or_image_space(): void
    {
        $this->signIn();
        $this->product('بدون تصویر چاپی');

        $this->get(route('admin.product-exports.print'))
            ->assertOk()
            ->assertSee('بدون تصویر چاپی')
            ->assertDontSee('placeholder-product', false)
            ->assertDontSee('<img class="product-image"', false);
    }

    public function test_category_filter_and_include_without_price_apply_to_print(): void
    {
        $this->signIn();
        $wanted = Category::create(['name' => 'دسته چاپ هدف']);
        $other = Category::create(['name' => 'دسته چاپ دیگر']);
        $included = $this->product('محصول بدون قیمت هدف', null, null, $wanted);
        $this->product('محصول خارج فیلتر', 1000, null, $other);

        $this->get(route('admin.product-exports.print', [
            'root_category_id' => $wanted->id,
            'include_without_price' => 1,
        ]))
            ->assertOk()
            ->assertSee($included->name)
            ->assertSee('قیمت ثبت نشده')
            ->assertDontSee('محصول خارج فیلتر');

        $this->get(route('admin.product-exports.print', ['root_category_id' => $wanted->id]))
            ->assertOk()
            ->assertDontSee($included->name);
    }

    public function test_catalog_preview_contract_remains_grouped_without_rowspan(): void
    {
        $this->signIn();
        $product = $this->product('گارد گروهی');
        $this->variant($product, 'iPhone 11', 'مشکی');
        $this->variant($product, 'iPhone 12', 'مشکی');

        $this->get(route('admin.product-exports.data', ['output_mode' => 'catalog']))
            ->assertOk()
            ->assertSee('iPhone 11')
            ->assertSee('iPhone 12')
            ->assertDontSee('rowspan=', false);
    }

    public function test_print_view_contract_uses_semantic_tables_and_safe_page_breaks(): void
    {
        $view = file_get_contents(resource_path('views/product-exports/print.blade.php'));

        foreach (['<!doctype html>', '<html lang="fa" dir="rtl">', '<thead>', '<tbody>', 'display: table-header-group', 'break-inside: avoid', 'print-product--large', '@media print', '.print-toolbar', 'window.print()'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        foreach (['@extends', 'window.onload', 'ProductPriceListPdfService', 'reserved', 'placeholder-product'] as $needle) {
            $this->assertStringNotContainsString($needle, $view);
        }
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

    private function product(string $name, ?int $price = 1000, ?string $image = null, ?Category $category = null): Product
    {
        $category ??= Category::firstOrCreate(['name' => 'Price list print test']);

        return Product::create([
            'name' => $name,
            'sku' => 'PRINT-'.uniqid(),
            'price' => $price ?? 0,
            'stock' => 1,
            'image_path' => $image,
            'category_id' => $category->id,
            'is_sellable' => true,
        ]);
    }

    private function variant(Product $product, string $model, string $color, ?int $price = 1890000, ?ModelList $modelList = null): ProductVariant
    {
        $modelList ??= ModelList::create(['brand' => 'Apple', 'model_name' => $model]);
        $colorModel = Color::create(['name' => $color, 'code' => 'PRINT-COLOR-'.uniqid()]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'model_list_id' => $modelList->id,
            'color_id' => $colorModel->id,
            'variant_name' => $model,
            'variety_name' => $color,
            'variant_code' => 'PRINT-VARIANT-'.uniqid(),
            'sell_price' => $price ?? 0,
            'stock' => 3,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
    }
}
