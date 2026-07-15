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
        $permission = Permission::findOrCreate('products.view', 'web');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_products_index_query_search_with_unmatched_text_returns_no_products(): void
    {
        $this->actingAsProductsViewer();

        $category = Category::create(['name' => 'لوازم جانبی']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'کاور کیفی مگنتی سامسونگ',
            'sku' => 'HTTP-SKU-001',
            'stock' => 5,
            'price' => 1000,
        ]);

        $response = $this->get('/products?q=zzzzzzzzzzzz');

        $response->assertOk();
        $response->assertDontSee('کاور کیفی مگنتی سامسونگ');
        $response->assertSee('۰ کالا');
    }

    public function test_products_index_keeps_search_strict_with_category_filter_and_pagination_links(): void
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

        for ($i = 1; $i <= 21; $i++) {
            Product::create([
                'category_id' => $samsung->id,
                'name' => "کیفی مگنتی سامسونگ صفحه {$i}",
                'sku' => "HTTP-SKU-PAGE-{$i}",
                'stock' => 5,
                'price' => 1000,
            ]);
        }

        $response = $this->get('/products?q=' . urlencode('کیفی مگنتی سامسون') . '&category_id=' . $samsung->id);

        $response->assertOk();
        $response->assertSee($matching->name);
        $response->assertDontSee('کابل سامسونگ');
        $response->assertDontSee('کاور کیفی مگنتی آیفون');
        $response->assertSee('q=%DA%A9%DB%8C%D9%81%DB%8C%20%D9%85%DA%AF%D9%86%D8%AA%DB%8C%20%D8%B3%D8%A7%D9%85%D8%B3%D9%88%D9%86', false);
        $response->assertSee('category_id=' . $samsung->id, false);
    }

    public function test_products_index_form_submits_one_canonical_q_field(): void
    {
        $this->actingAsProductsViewer();

        $response = $this->get('/products?q=' . urlencode('سامسونگ'));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'name="q"'));
        $response->assertSee('id="productSearchQuery"', false);
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
