<?php
namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use App\Models\Product;
use App\Services\ProductExportService;
use App\Support\PageAccessCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductExportImageRouteTest extends TestCase
{
    public function test_export_image_url_resolves_to_existing_image_controller_with_page_access(): void
    {
        $product=new Product(['image_path'=>'products/example.jpg']);$product->id=913;
        $url=app(ProductExportService::class)->imagePath($product);
        $route=Route::getRoutes()->match(Request::create($url));
        $this->assertSame('admin.product-exports.products.image',$route->getName());
        $this->assertSame(ProductController::class.'@image',$route->getActionName());
        $this->assertSame('913',$route->parameter('product'));
        $this->assertContains('page.products',PageAccessCatalog::permissionsForRoute($route->getName()));
    }
    public function test_product_without_image_does_not_generate_image_request(): void
    {
        $this->assertNull(app(ProductExportService::class)->imagePath(new Product()));
    }
    public function test_export_home_and_builder_routes_keep_the_interactive_version(): void
    {
        foreach(['index'=>['GET','/admin/product-exports','index'], 'builder.products'=>['GET','/admin/product-exports/builder/products','products'], 'builder.preview'=>['POST','/admin/product-exports/builder/preview','preview'], 'builder.print'=>['POST','/admin/product-exports/builder/print','print']] as $name=>[$method,$path,$action]) {
            $route=Route::getRoutes()->match(Request::create($path,$method));
            $this->assertSame('admin.product-exports.'.$name,$route->getName());
            $this->assertSame(\App\Http\Controllers\ProductExportBuilderController::class.'@'.$action,$route->getActionName());
            $this->assertContains('page.products',PageAccessCatalog::permissionsForRoute($route->getName()));
        }
    }
}
