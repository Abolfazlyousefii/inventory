<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriceChangeDocumentProductPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_create_permission_cannot_search_products(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('products.price-changes.products.search'))
            ->assertForbidden();
    }

    public function test_unrelated_subcategory_is_rejected(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'Root']);
        $other = Category::create(['name' => 'Other']);

        $this->getJson(route('products.price-changes.products.search', [
            'category_id' => $root->id,
            'subcategory_id' => $other->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_picker_paginates_all_products_in_subcategory_descendants_without_leaking_other_categories(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'Root']);
        $subcategory = Category::create(['name' => 'Phones', 'parent_id' => $root->id]);
        $descendant = Category::create(['name' => 'Android', 'parent_id' => $subcategory->id]);
        $other = Category::create(['name' => 'Other']);

        foreach (range(1, 25) as $number) {
            $product = $this->product($number % 2 ? $subcategory : $descendant, sprintf('Phone %02d', $number), "SKU-{$number}");
            $this->variant($product, "VAR-{$number}");
        }
        $outside = $this->product($other, 'Outside', 'OUT-1');
        $this->variant($outside, 'OUT-VAR');

        $first = $this->getJson($this->searchUrl($root, $subcategory, ['page' => 1, 'per_page' => 20]))
            ->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('pagination.more', true);
        $second = $this->getJson($this->searchUrl($root, $subcategory, ['page' => 2, 'per_page' => 20]))
            ->assertOk()->assertJsonPath('pagination.more', false);

        $firstIds = collect($first->json('results'))->pluck('id');
        $secondIds = collect($second->json('results'))->pluck('id');
        $this->assertCount(20, $firstIds);
        $this->assertCount(5, $secondIds);
        $this->assertEmpty($firstIds->intersect($secondIds));
        $this->assertNotContains($outside->id, $firstIds->merge($secondIds));
    }

    public function test_picker_searches_product_sku_and_variant_code_and_returns_product_once(): void
    {
        $this->signIn();
        $root = Category::create(['name' => 'Root']);
        $subcategory = Category::create(['name' => 'Accessories', 'parent_id' => $root->id]);
        $product = $this->product($subcategory, 'کیف چرمی', 'SKU-123');
        $this->variant($product, 'MODEL-ABC', 'مدل ویژه');
        $this->variant($product, 'MODEL-ABC-2', 'مدل ویژه دوم');

        foreach (['SKU-123', 'MODEL-ABC', 'كيف چرمي'] as $term) {
            $response = $this->getJson($this->searchUrl($root, $subcategory, ['q' => $term]))->assertOk();
            $this->assertSame([$product->id], collect($response->json('results'))->pluck('id')->all());
        }
    }

    private function signIn(): void
    {
        $role = Role::findOrCreate('price-change-creator', 'web');
        $role->givePermissionTo(Permission::findOrCreate('products.price_changes.create', 'web'));
        $pageId = DB::table('permissions')->insertGetId(['key'=>'page.products.price_changes','name'=>'page.products.price_changes','group'=>'page-test','guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);
        DB::table('role_has_permissions')->insert(['role_id'=>$role->id,'permission_id'=>$pageId]);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function product(Category $category, string $name, string $sku): Product
    {
        return Product::create(['category_id' => $category->id, 'name' => $name, 'sku' => $sku, 'stock' => 1, 'price' => 1000, 'is_sellable' => true]);
    }

    private function variant(Product $product, string $code, string $name = 'Default'): ProductVariant
    {
        return ProductVariant::create(['product_id' => $product->id, 'variant_name' => $name, 'variant_code' => $code, 'sell_price' => 1000, 'stock' => 1, 'is_active' => true]);
    }

    private function searchUrl(Category $root, Category $subcategory, array $extra = []): string
    {
        return route('products.price-changes.products.search', array_merge([
            'category_id' => $root->id,
            'subcategory_id' => $subcategory->id,
        ], $extra));
    }
}
