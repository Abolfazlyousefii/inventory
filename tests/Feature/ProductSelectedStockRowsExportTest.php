<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductSelectedStockRowsExportTest extends TestCase
{
    use RefreshDatabase;

    private function login(bool $canExport = true): void
    {
        $role = Role::findOrCreate($canExport ? 'stock-exporter' : 'stock-viewer', 'web');
        $page = Permission::query()->where('key', 'page.products')->first()
            ?? Permission::findOrCreate('page.products', 'web');
        if ($page->key !== 'page.products') $page->forceFill(['key' => 'page.products'])->save();
        $role->givePermissionTo($page);
        $role->givePermissionTo(Permission::findOrCreate('products.view', 'web'));
        if ($canExport) $role->givePermissionTo(Permission::findOrCreate('products.export', 'web'));
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function product(string $name, int $stock = 0): Product
    {
        $category = Category::firstOrCreate(['name' => 'دسته آزمون خروجی موجودی']);
        return Product::create([
            'name' => $name, 'sku' => 'EXPORT-'.uniqid(), 'category_id' => $category->id,
            'price' => 120_000, 'stock' => $stock, 'is_sellable' => true,
        ]);
    }

    private function variant(Product $product, string $name, int $stock, bool $active = true): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'variant_name' => $name,
            'variant_code' => 'V-'.uniqid(), 'sell_price' => 140_000,
            'stock' => $stock, 'reserved' => 0,
            'is_active' => $active, 'sales_enabled' => true,
        ]);
    }

    public function test_start_and_batch_export_only_positive_active_variants_one_row_each_and_simple_stock_products(): void
    {
        $this->login();
        $product = $this->product('قاب گوشی');
        $first = $this->variant($product, 'آبی', 8);
        $second = $this->variant($product, 'قرمز', 2);
        $this->variant($product, 'ناموجود', 0);
        $this->variant($product, 'غیرفعال', 20, false);
        $simple = $this->product('کالای بدون تنوع', 5);
        $empty = $this->product('کالای خالی', 0);

        $ids = [$product->id, $simple->id, $empty->id];
        $this->postJson(route('products.selected-stock-export.start'), ['product_ids' => $ids])
            ->assertOk()->assertJsonPath('total_rows', 3)->assertJsonPath('batch_size', 300);

        $data = $this->postJson(route('products.selected-stock-export.batch'), [
            'product_ids' => $ids, 'after_id' => 0,
        ])->assertOk()->assertJsonPath('has_more', false)->json();

        $this->assertCount(3, $data['rows']);
        $this->assertSame([(int) $first->id, (int) $second->id], array_column(
            array_values(array_filter($data['rows'], fn ($r) => $r['variant_id'] !== null)), 'variant_id'
        ));
        $this->assertSame('قاب گوشی', $data['rows'][1]['product_name']);
        $this->assertSame(8, $data['rows'][1]['stock']);
        $this->assertSame(140_000, $data['rows'][1]['sell_price_rial']);
        $this->assertSame(null, $data['rows'][0]['variant_id']);
        $this->assertSame((int) $simple->id, $data['rows'][0]['product_id']);
        $this->assertSame(5, $data['rows'][0]['stock']);
    }

    public function test_pagination_is_bounded_cursor_based_and_does_not_repeat_variant_rows(): void
    {
        $this->login();
        $product = $this->product('با تنوع‌های زیاد');
        for ($i = 0; $i < 303; $i++) {
            $this->variant($product, 'variant-'.$i, 1);
        }

        $ids = ['product_ids' => [$product->id]];
        $this->postJson(route('products.selected-stock-export.start'), $ids)
            ->assertOk()->assertJsonPath('total_rows', 303);

        $first = $this->postJson(route('products.selected-stock-export.batch'), $ids + ['after_id' => 0])
            ->assertOk()->assertJsonCount(300, 'rows')->assertJsonPath('has_more', true)->json();
        $second = $this->postJson(route('products.selected-stock-export.batch'), $ids + ['after_id' => $first['next_cursor']])
            ->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('has_more', false)->json();

        $allIds = array_column([...$first['rows'], ...$second['rows']], 'variant_id');
        $this->assertCount(303, array_unique($allIds));
        $this->assertSame((int) end($allIds), $second['next_cursor']);
    }

    public function test_print_preview_uses_a_lightweight_html_shell_for_multiple_products_without_generating_pdf(): void
    {
        $this->login();
        $products = [];
        foreach (range(1, 7) as $index) {
            $products[] = $this->product('چاپ کالا '.$index, 5)->id;
        }

        $response = $this->post(route('products.selected-stock-print'), ['product_ids' => $products]);
        $response->assertOk()->assertViewIs('products.stock-print')
            ->assertViewHas('productIds', $products)
            ->assertSee('چاپ / ذخیره PDF')
            ->assertSee('در حال دریافت اقلام')
            ->assertSee(route('products.selected-stock-export.start'), false);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('products', count($products));
    }

    public function test_print_preview_denies_unauthorized_users_and_bad_or_duplicate_selections(): void
    {
        $p = $this->product('محافظت چاپ', 4);
        $this->login(false);
        $this->post(route('products.selected-stock-print'), ['product_ids' => [$p->id]])->assertForbidden();

        $this->login();
        $this->postJson(route('products.selected-stock-print'), ['product_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $this->postJson(route('products.selected-stock-print'), ['product_ids' => [$p->id, $p->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('product_ids.1');
        $this->postJson(route('products.selected-stock-print'), ['product_ids' => [900000]])
            ->assertUnprocessable()->assertJsonValidationErrors('product_ids');
    }

    public function test_print_preview_allows_more_than_two_thousand_rows_without_server_side_pdf_generation(): void
    {
        $this->login();
        $product = $this->product('بسته بزرگ برای تست سقف چاپ');
        $now = now();
        $rows = [];
        foreach (range(1, 2001) as $i) {
            $rows[] = [
                'product_id' => $product->id,
                'variant_name' => 'چاپ '.$i,
                'variant_code' => 'PRINT-LIMIT-'.$i,
                'sell_price' => 1000,
                'stock' => 1,
                'reserved' => 0,
                'is_active' => true,
                'sales_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 80) as $chunk) {
            \Illuminate\Support\Facades\DB::table('product_variants')->insert($chunk);
        }

        $response = $this->post(route('products.selected-stock-print'), [
            'product_ids' => [$product->id],
        ]);
        $response->assertOk()->assertViewIs('products.stock-print')
            ->assertViewHas('productIds', [$product->id])
            ->assertSee('چاپ / ذخیره PDF')
            ->assertDontSee('حداکثر 2000 ردیف')
            ->assertDontSee('data-max-rows=', false);
        $this->postJson(route('products.selected-stock-export.start'), [
            'product_ids' => [$product->id],
        ])->assertOk()->assertJsonPath('total_rows', 2001);
        $this->assertSame(2001, ProductVariant::query()->where('product_id', $product->id)->count());
    }

    public function test_print_uses_existing_local_vazirmatn_woff2_for_screen_and_print(): void
    {
        $this->login();
        $product = $this->product('فونت وزیرمتن', 5);
        $this->post(route('products.selected-stock-print'), ['product_ids' => [$product->id]])
            ->assertOk()
            ->assertSee('font-family: Vazirmatn', false)
            ->assertSee('fonts/vazirmatn/Vazirmatn-Regular.woff2', false)
            ->assertSee('fonts/vazirmatn/Vazirmatn-Bold.woff2', false);
    }

    public function test_catalog_offers_print_pdf_button_instead_of_excel_or_heavy_pdf_actions(): void
    {
        $this->login();
        $this->get(route('products.index'))->assertOk()
            ->assertSee('id="downloadSelectedProducts"', false)
            ->assertSee('چاپ / ذخیره PDF')
            ->assertDontSee('خروجی ریز موجودی (Excel)')
            ->assertDontSee('id="downloadSelectedProductsPdf"', false)
            ->assertSee('class="product-select"', false);
    }

    public function test_export_requires_permission_and_rejects_bad_selection(): void
    {
        $product = $this->product('مجاز');
        $this->login(false);
        $this->postJson(route('products.selected-stock-export.start'), ['product_ids' => [$product->id]])
            ->assertForbidden();

        $this->login();
        $this->postJson(route('products.selected-stock-export.start'), ['product_ids' => []])
            ->assertUnprocessable();
        $this->postJson(route('products.selected-stock-export.start'), ['product_ids' => [$product->id, $product->id]])
            ->assertUnprocessable();
        $this->postJson(route('products.selected-stock-export.start'), ['product_ids' => [999999]])
            ->assertUnprocessable();
        $this->postJson(route('products.selected-stock-export.batch'), ['product_ids' => [$product->id], 'after_id' => -1])
            ->assertUnprocessable();
    }
}
