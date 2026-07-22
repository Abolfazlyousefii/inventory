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

class ProductPriceListPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ProductPriceListPdfService::class, new class extends ProductPriceListPdfService {
            public array $lastProducts = [];
            public function render(array $products, array $meta): string { $this->lastProducts = $products; return "%PDF-1.4\n% fake آریا گستر\n"; }
        });
    }

    public function test_pdf_route_returns_application_pdf(): void { $this->signIn(); $this->product('گارد'); $this->get(route('admin.product-exports.download'))->assertOk()->assertHeader('Content-Type', 'application/pdf'); }
    public function test_pdf_starts_with_pdf_signature(): void { $this->signIn(); $this->product('گارد'); $this->assertStringStartsWith('%PDF-', $this->get(route('admin.product-exports.download'))->getContent()); }
    public function test_pdf_has_download_filename(): void { $this->signIn(); $this->product('گارد'); $this->get(route('admin.product-exports.download'))->assertHeader('Content-Disposition', fn ($v) => str_contains($v, 'attachment') && str_contains($v, 'aria-gostar-price-list-')); }
    public function test_pdf_does_not_contain_laravel_label(): void { $this->signIn(); $this->product('گارد'); $this->assertStringNotContainsString('Laravel', $this->get(route('admin.product-exports.download'))->getContent()); }
    public function test_single_output_mode_only(): void { $this->signIn(); $this->get(route('admin.product-exports.index'))->assertOk()->assertSee('دانلود لیست قیمت PDF')->assertDontSee('کاتالوگ مشتری')->assertDontSee('name="output_mode"', false); }
    public function test_output_mode_is_not_required(): void { $this->signIn(); $this->product('گارد'); $this->get(route('admin.product-exports.download'))->assertOk(); }

    public function test_products_are_grouped_without_rowspan(): void
    {
        $this->signIn(); $p=$this->product('گارد گروهی'); $this->variant($p,'iPhone 11','مشکی'); $this->variant($p,'iPhone 12','مشکی');
        $this->get(route('admin.product-exports.data'))->assertOk()->assertSee('iPhone 11')->assertSee('iPhone 12')->assertDontSee('rowspan=', false);
    }

    public function test_product_without_image_has_no_placeholder(): void { $this->signIn(); $this->product('بدون تصویر'); $this->get(route('admin.product-exports.data'))->assertOk()->assertDontSee('placeholder-product')->assertDontSee('price-list-product-image'); }

    public function test_filters_still_apply(): void
    {
        $this->signIn(); $cat=Category::create(['name'=>'گارد']); $other=Category::create(['name'=>'کابل']); $this->product('محصول گارد',1000,null,$cat); $this->product('محصول کابل',1000,null,$other);
        $this->get(route('admin.product-exports.data',['root_category_id'=>$cat->id]))->assertOk()->assertSee('محصول گارد')->assertDontSee('محصول کابل');
    }

    public function test_product_without_price_is_hidden_by_default(): void { $this->signIn(); $p=$this->product('بدون قیمت',null); $this->variant($p,'A15','مشکی',null); $this->get(route('admin.product-exports.data'))->assertOk()->assertDontSee('بدون قیمت'); }
    public function test_product_without_price_can_be_included(): void { $this->signIn(); $p=$this->product('بدون قیمت',null); $this->variant($p,'A15','مشکی',null); $this->get(route('admin.product-exports.data',['include_without_price'=>1]))->assertOk()->assertSee('قیمت ثبت نشده'); }

    public function test_view_contract(): void
    {
        $pdfView = file_get_contents(resource_path('views/product-exports/price-list-pdf.blade.php'));
        $previewView = file_get_contents(resource_path('views/product-exports/partials/clean-price-list.blade.php'));
        $colorView = file_get_contents(resource_path('views/product-exports/partials/color-list.blade.php'));
        $view = $pdfView.$previewView.$colorView;

        foreach (['rowspan=','catalog-card','output_mode','Laravel','placeholder-product','window.print'] as $needle) $this->assertStringNotContainsString($needle, $view);
        foreach (['price-list-product-header','price-list-detail-row','price-list-models','price-list-colors','price-list-price'] as $needle) $this->assertStringContainsString($needle, $view);
        foreach (['<colgroup>', 'width: 46%', 'width: 38%', 'width: 16%', 'product-header-row', 'column-header-row', 'dir="ltr"', 'model-token', 'colors-grid', 'white-space:nowrap', 'border-left:0.6px solid #D7E2E8', 'product-price-table--large'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        $this->assertMatchesRegularExpression('/<thead><tr class="product-header-row">.*<tr class="column-header-row">/s', $pdfView);
    }

    private function signIn(): void { $role=Role::findOrCreate('products-viewer','web'); $permission=Permission::findOrCreate('products.view','web'); $role->givePermissionTo($permission); $user=User::factory()->create(); $user->assignRole($role); $this->actingAs($user); }
    private function product(string $name, ?int $price=1000, ?string $image=null, ?Category $category=null): Product { return Product::create(['name'=>$name,'price'=>$price,'stock'=>1,'image_path'=>$image,'category_id'=>$category?->id,'is_sellable'=>true]); }
    private function variant(Product $product,string $model,string $color,?int $price=1890000,?ModelList $modelList=null): ProductVariant { $modelList ??= ModelList::create(['brand'=>'Apple','model_name'=>$model]); $colorModel=Color::create(['name'=>$color]); return ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$modelList->id,'color_id'=>$colorModel->id,'variant_name'=>$model,'variety_name'=>$color,'sell_price'=>$price,'stock'=>3,'is_active'=>true,'sales_enabled'=>true]); }
}
