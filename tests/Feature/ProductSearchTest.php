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

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsProductsViewer(): User
    {
        $role = Role::findOrCreate('products-viewer', 'web');
        $permission = Permission::query()->where('key', 'page.products')->first()
            ?? Permission::findOrCreate('page.products', 'web');
        if ($permission->key !== 'page.products') {
            $permission->forceFill(['key' => 'page.products'])->save();
        }
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_products_index_exposes_ajax_search_shell_with_initial_filters(): void
    {
        $this->actingAsProductsViewer();

        $category = Category::create(['name' => 'سامسونگ']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'کاور کیفی مگنتی سامسونگ',
            'sku' => 'HTTP-SKU-001',
            'stock' => 5,
            'price' => 1000,
        ]);

        $response = $this->get('/products?q=' . urlencode('کیفی مگنتی سامسون') . '&category_id=' . $category->id);

        $response->assertOk();
        $response->assertSee('id="productSearch"', false);
        $response->assertSee('data-products-url="' . route('products.data') . '"', false);
        $response->assertSee('data-initial-filters', false);
        $response->assertSee('"q":"کیفی مگنتی سامسون"', false);
        $response->assertSee('"category_id":"' . $category->id . '"', false);
        $response->assertDontSee('کاور کیفی مگنتی سامسونگ');
    }

    public function test_products_data_keeps_search_strict_with_category_filter_and_cursor_meta(): void
    {
        $this->actingAsProductsViewer();

        $samsung = Category::create(['name' => 'سامسونگ']);
        $apple = Category::create(['name' => 'آیفون']);

        $matching = Product::create([
            'category_id' => $samsung->id,
            'name' => 'کاور کیفی مگنتی سامسونگ',
            'sku' => 'HTTP-SKU-101',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $samsung->id,
            'name' => 'کابل سامسونگ',
            'sku' => 'HTTP-SKU-102',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $apple->id,
            'name' => 'کاور کیفی مگنتی آیفون',
            'sku' => 'HTTP-SKU-103',
            'stock' => 5,
            'price' => 1000,
        ]);

        $response = $this->getJson('/products/data?q=' . urlencode('کیفی مگنتی سامسون') . '&category_id=' . $samsung->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'short_code', 'category', 'category_path', 'variants_count', 'total_stock', 'reserved', 'min_price', 'max_price', 'sales_enabled', 'image_url', 'updated_at', 'routes']],
                'meta' => ['returned', 'has_more', 'next_cursor'],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($matching->id, $ids);
        $this->assertNotContains(Product::where('sku', 'HTTP-SKU-102')->value('id'), $ids);
        $this->assertNotContains(Product::where('sku', 'HTTP-SKU-103')->value('id'), $ids);
        $this->assertSame(1, $response->json('meta.returned'));
        $this->assertFalse($response->json('meta.has_more'));
        $this->assertNull($response->json('meta.next_cursor'));
    }

    public function test_products_index_form_submits_one_canonical_q_field(): void
    {
        $this->actingAsProductsViewer();

        $response = $this->get('/products?q=' . urlencode('سامسونگ'));

        $response->assertOk();
        $this->assertSame(0, substr_count($response->getContent(), 'name="q"'));
        $response->assertSee('id="productSearch"', false);
    }

    public function test_multi_word_product_search_requires_every_token_across_searchable_fields(): void
    {
        $category = Category::create(['name' => 'لوازم جانبی']);

        $bothInName = Product::create([
            'category_id' => $category->id,
            'name' => 'کاور کیفی مگنتی سامسونگ',
            'sku' => 'SKU-001',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'کاور کیفی ساده',
            'sku' => 'SKU-002',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'هولدر مگنتی خودرو',
            'sku' => 'SKU-003',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'کابل سامسونگ',
            'sku' => 'SKU-003-A',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'شارژر سامسونگ',
            'sku' => 'SKU-003-B',
            'stock' => 5,
            'price' => 1000,
        ]);

        $bothAcrossVariant = Product::create([
            'category_id' => $category->id,
            'name' => 'قاب سامسونگ',
            'sku' => 'SKU-004',
            'stock' => 5,
            'price' => 1000,
        ]);
        ProductVariant::create([
            'product_id' => $bothAcrossVariant->id,
            'variant_name' => 'مدل مگنتی',
            'variety_name' => 'کیفی',
            'variant_code' => 'VM-004',
            'sell_price' => 1000,
            'stock' => 5,
        ]);

        $results = Product::query()->search('کیفی مگنتی سامسون')->pluck('id')->all();

        $this->assertContains($bothInName->id, $results);
        $this->assertContains($bothAcrossVariant->id, $results);
        $this->assertCount(2, $results);
    }

    public function test_multi_word_product_search_can_match_category_and_keeps_category_filters(): void
    {
        $samsung = Category::create(['name' => 'سامسونگ']);
        $apple = Category::create(['name' => 'اپل']);

        $samsungCase = Product::create([
            'category_id' => $samsung->id,
            'name' => 'کاور کیفی',
            'sku' => 'SKU-101',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $apple->id,
            'name' => 'کاور کیفی',
            'sku' => 'SKU-102',
            'stock' => 5,
            'price' => 1000,
        ]);

        $results = Product::query()
            ->where('category_id', $samsung->id)
            ->search('کیفی سامسونگ')
            ->pluck('id')
            ->all();

        $this->assertSame([$samsungCase->id], $results);
    }

    public function test_multi_word_product_search_requires_all_tokens_for_iphone_query(): void
    {
        $category = Category::create(['name' => 'لوازم جانبی']);

        $iphoneCase = Product::create([
            'category_id' => $category->id,
            'name' => 'کیفی مگنتی آیفون ۱۵',
            'sku' => 'SKU-150',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'کیفی مگنتی سامسونگ',
            'sku' => 'SKU-151',
            'stock' => 5,
            'price' => 1000,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'کابل آیفون',
            'sku' => 'SKU-152',
            'stock' => 5,
            'price' => 1000,
        ]);

        $this->assertSame([$iphoneCase->id], Product::query()->search('کیفی مگنتی آیفون')->pluck('id')->all());
    }

    public function test_single_word_and_code_search_still_match_related_products(): void
    {
        $category = Category::create(['name' => 'لوازم جانبی']);

        $magnetic = Product::create([
            'category_id' => $category->id,
            'name' => 'هولدر مگنتی',
            'sku' => 'SKU-201',
            'code' => 'PRD-201',
            'barcode' => '6260000000201',
            'short_barcode' => '0201',
            'stock' => 5,
            'price' => 1000,
        ]);

        $byVariantCode = Product::create([
            'category_id' => $category->id,
            'name' => 'کاور گوشی',
            'sku' => 'SKU-202',
            'stock' => 5,
            'price' => 1000,
        ]);
        ProductVariant::create([
            'product_id' => $byVariantCode->id,
            'variant_name' => 'مدل چرمی',
            'variant_code' => 'MAG-202',
            'sell_price' => 1000,
            'stock' => 5,
        ]);

        $this->assertContains($magnetic->id, Product::query()->search('مگنتی')->pluck('id')->all());
        $this->assertSame([$magnetic->id], Product::query()->search('6260000000201')->pluck('id')->all());
        $this->assertSame([$byVariantCode->id], Product::query()->search('MAG-202')->pluck('id')->all());
    }
}
