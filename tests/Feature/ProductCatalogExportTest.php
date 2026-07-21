<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCatalogExportTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $role = Role::findOrCreate('products-viewer', 'web');
        $permission = Permission::findOrCreate('products.view', 'web');
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_index_only_shows_root_categories_in_root_filter(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'Root']);
        $child = Category::create(['name' => 'Child', 'parent_id' => $root->id]);

        $this->get(route('admin.product-exports.index'))->assertOk()
            ->assertSee('Root')
            ->assertDontSee('<option value="'.$child->id.'" >Child</option>', false);
    }

    public function test_children_endpoint_returns_only_selected_parent_children(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'Root']);
        $wanted = Category::create(['name' => 'Wanted', 'parent_id' => $root->id]);
        Category::create(['name' => 'Other child', 'parent_id' => Category::create(['name' => 'Other'])->id]);

        $this->getJson(route('admin.product-exports.categories.children', $root))
            ->assertOk()->assertJsonPath('items.0.id', $wanted->id)->assertJsonMissing(['name' => 'Other child']);
    }

    public function test_root_category_without_child_includes_descendants(): void
    {
        $this->signIn();
        [$root, $child] = $this->categoryTree();
        $product = $this->product('Descendant product', $child);

        $this->get(route('admin.product-exports.data', ['root_category_id' => $root->id]))
            ->assertOk()->assertSee($product->name);
    }

    public function test_selected_subcategory_filters_products_correctly_and_excludes_unrelated(): void
    {
        $this->signIn();
        [, $child] = $this->categoryTree();
        $included = $this->product('Included product', $child);
        $excluded = $this->product('Excluded product', Category::create(['name' => 'Other']));

        $this->get(route('admin.product-exports.data', ['subcategory_id' => $child->id]))
            ->assertOk()->assertSee($included->name)->assertDontSee($excluded->name);
    }

    public function test_multiple_model_lists_filter_visible_variants(): void
    {
        $this->signIn();
        $product = $this->product('Multi model');
        [$a, $b, $c] = [ModelList::create(['model_name' => 'A']), ModelList::create(['model_name' => 'B']), ModelList::create(['model_name' => 'C'])];
        $this->variant($product, $a, 'Variant A'); $this->variant($product, $b, 'Variant B'); $this->variant($product, $c, 'Variant C');

        $this->get(route('admin.product-exports.data', ['model_list_ids' => [$a->id, $c->id]]))
            ->assertOk()->assertSee('Multi model')->assertSee('Variant A')->assertSee('Variant C')->assertDontSee('Variant B');
    }

    public function test_product_without_matching_model_variant_is_excluded_and_empty_model_shows_all(): void
    {
        $this->signIn();
        $product = $this->product('All variants');
        [$a, $b] = [ModelList::create(['model_name' => 'A']), ModelList::create(['model_name' => 'B'])];
        $this->variant($product, $a, 'Variant A');

        $this->get(route('admin.product-exports.data', ['model_list_ids' => [$b->id]]))->assertOk()->assertDontSee('All variants');
        $this->get(route('admin.product-exports.data'))->assertOk()->assertSee('Variant A');
    }

    public function test_in_stock_filter_uses_visible_variants(): void
    {
        $this->signIn();
        $product = $this->product('Stock model');
        [$a, $b] = [ModelList::create(['model_name' => 'A']), ModelList::create(['model_name' => 'B'])];
        $this->variant($product, $a, 'A zero', 0); $this->variant($product, $b, 'B stock', 10);

        $this->get(route('admin.product-exports.data', ['model_list_ids' => [$a->id], 'stock_status' => 'in_stock']))->assertOk()->assertDontSee('Stock model');
        $this->get(route('admin.product-exports.data', ['model_list_ids' => [$b->id], 'stock_status' => 'in_stock']))->assertOk()->assertSee('Stock model');
        $this->get(route('admin.product-exports.data', ['model_list_ids' => [$a->id], 'stock_status' => 'out_of_stock']))->assertOk()->assertSee('Stock model');
    }

    public function test_print_route_and_old_export_route(): void
    {
        $this->signIn();
        $product = $this->product('Printable product', null, 25000, 'products/demo.jpg');
        $model = ModelList::create(['model_name' => 'Print Model']);
        $this->variant($product, $model, 'Printable Variant', 4, 12000);

        $this->get(route('admin.product-exports.print'))->assertOk()
            ->assertSee('lang="fa"', false)->assertSee('dir="rtl"', false)->assertSee('چاپ محصولات')
            ->assertSee('Printable product')->assertSee('12,000 ریال')->assertSee('Printable Variant')
            ->assertSee(route('products.image', $product))->assertDontSee('مجموع موجودی')->assertDontSee('موجودی فعلی')->assertDontSee('انبار');
        $this->get(route('admin.product-exports.export', ['stock_status' => 'in_stock']))->assertRedirect(route('admin.product-exports.print', ['stock_status' => 'in_stock']));
    }

    public function test_screen_paginates_24_products_and_query_count_does_not_grow_linearly(): void
    {
        $this->signIn();
        $model = ModelList::create(['model_name' => 'Performance']);
        for ($i = 1; $i <= 500; $i++) {
            $product = $this->product('Performance '.$i);
            for ($j = 1; $j <= 5; $j++) $this->variant($product, $model, "V{$i}-{$j}", $j);
        }

        DB::enableQueryLog();
        $response = $this->get(route('admin.product-exports.data'));
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertSee('Performance 1')->assertDontSee('Performance 25');
        $this->assertLessThan(20, $queryCount);
    }

    private function categoryTree(): array
    {
        $root = Category::create(['name' => 'Root']);
        return [$root, Category::create(['name' => 'Child', 'parent_id' => $root->id])];
    }

    private function product(string $name, ?Category $category = null, int $price = 1000, ?string $imagePath = null): Product
    {
        return Product::create(['name' => $name, 'category_id' => $category?->id, 'price' => $price, 'stock' => 1, 'image_path' => $imagePath, 'is_sellable' => true]);
    }

    private function variant(Product $product, ModelList $model, string $name, int $stock = 3, int $price = 2000): ProductVariant
    {
        return ProductVariant::create(['product_id' => $product->id, 'model_list_id' => $model->id, 'variant_name' => $name, 'sell_price' => $price, 'stock' => $stock, 'is_active' => true, 'sales_enabled' => true]);
    }
}
