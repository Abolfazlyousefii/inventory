<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSelectionPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductSelectionExportTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(bool $canExport): void
    {
        $role = Role::findOrCreate($canExport ? 'catalog-exporter' : 'catalog-viewer', 'web');
        $pagePermission = Permission::query()->where('key', 'page.products')->first()
            ?? Permission::findOrCreate('page.products', 'web');
        if ($pagePermission->key !== 'page.products') {
            $pagePermission->forceFill(['key' => 'page.products'])->save();
        }
        $role->givePermissionTo($pagePermission);
        $role->givePermissionTo(Permission::findOrCreate('products.view', 'web'));
        if ($canExport) {
            $role->givePermissionTo(Permission::findOrCreate('products.export', 'web'));
        }
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_selected_products_download_as_pdf_with_active_variants_and_embedded_image(): void
    {
        $this->signIn(true);
        Storage::fake('public');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII=');
        Storage::disk('public')->put('products/catalog.png', $image);
        $category = Category::create(['name' => 'آزمایش خروجی']);
        $product = Product::create([
            'name' => 'کالای آزمایشی', 'sku' => 'CAT-PDF-1', 'category_id' => $category->id,
            'image_path' => 'products/catalog.png', 'price' => 1000, 'stock' => 0,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'variant_name' => 'مدل ناموجود',
            'sell_price' => 2500, 'stock' => 0, 'is_active' => true, 'sales_enabled' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'variant_name' => 'مدل غیرفعال',
            'sell_price' => 3000, 'stock' => 3, 'is_active' => false, 'sales_enabled' => true,
        ]);

        $rows = app(ProductSelectionPdfService::class)->buildRows([$product->id]);
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['variants']);
        $this->assertSame('مدل ناموجود', $rows[0]['variants'][0]['model']);
        $this->assertSame(0, $rows[0]['variants'][0]['stock']);
        $this->assertSame(2500, $rows[0]['variants'][0]['price']);
        $this->assertStringStartsWith('data:image/png;base64,', $rows[0]['image_data']);

        $response = $this->post(route('products.selected-export'), ['product_ids' => [$product->id]]);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_view_only_user_cannot_export_and_empty_selection_is_rejected(): void
    {
        $category = Category::create(['name' => 'آزمایش خروجی']);
        $product = Product::create(['name' => 'کالا', 'sku' => 'CAT-PDF-2', 'category_id' => $category->id, 'price' => 1000, 'stock' => 0]);
        $this->signIn(false);
        $this->post(route('products.selected-export'), ['product_ids' => [$product->id]])->assertForbidden();

        $this->signIn(true);
        $this->postJson(route('products.selected-export'), ['product_ids' => []])->assertUnprocessable();
    }
}
