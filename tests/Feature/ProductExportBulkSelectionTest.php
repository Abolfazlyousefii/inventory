<?php

namespace Tests\Feature;

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportBulkSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->withoutMiddleware(RoutePermissionMiddleware::class);
        $role = Role::findOrCreate('products-viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('products.view', 'web'));
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function product(string $name, Category $category): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => 'BULK-'.uniqid(),
            'category_id' => $category->id,
            'price' => 1000,
            'stock' => 2,
            'is_sellable' => true,
        ]);
    }

    public function test_bulk_endpoint_collects_every_matching_product_across_multiple_batches(): void
    {
        $this->signIn();
        $root = Category::query()->create(['name' => 'Bulk root']);
        $child = Category::query()->create(['name' => 'Bulk child', 'parent_id' => $root->id]);
        $outside = Category::query()->create(['name' => 'Other category']);

        for ($n = 1; $n <= 1001; $n++) {
            $this->product('Export selected '.$n, $n % 2 ? $root : $child);
        }
        $this->product('Do not include', $outside);

        $cursor = 0;
        $pages = 0;
        $rows = [];
        do {
            $response = $this->getJson(route('admin.product-exports.builder.selection-batch', [
                'category_id' => $root->id,
                'after_id' => $cursor,
            ]))->assertOk();

            if ($pages === 0) {
                $response->assertJsonCount(80, 'items')
                    ->assertJsonPath('has_more', true)
                    ->assertJsonPath('total', 1001);
            }
            $rows = array_merge($rows, $response->json('items'));
            $nextCursor = $response->json('next_cursor');
            $this->assertGreaterThan($cursor, $nextCursor);
            $cursor = $nextCursor;
            $pages++;
        } while ($response->json('has_more'));

        $this->assertGreaterThan(12, $pages);
        $this->assertCount(1001, array_unique(array_column($rows, 'id')));
        $this->assertNotContains('Do not include', array_column($rows, 'name'));
        $this->assertSame(['مدل عمومی'], array_column($rows[0]['models'], 'name'));
    }

    public function test_print_accepts_more_than_two_hundred_selected_products_in_one_json_field(): void
    {
        $this->signIn();
        $category = Category::query()->create(['name' => 'Large output']);
        $selection = [];
        for ($n = 1; $n <= 205; $n++) {
            $product = $this->product('Bulk print '.$n, $category);
            $selection[$product->id] = [0];
        }

        $this->post(route('admin.product-exports.builder.print'), [
            'selection_payload' => json_encode($selection, JSON_THROW_ON_ERROR),
            'show_price' => '1',
        ])->assertOk()
            ->assertSee('Bulk print 1')
            ->assertSee('Bulk print 205')
            ->assertSee('Bulk print 100');
    }

    public function test_rejects_invalid_json_selection_and_keeps_legacy_form_selection(): void
    {
        $this->signIn();
        $category = Category::query()->create(['name' => 'Legacy']);
        $product = $this->product('Legacy selected', $category);

        $this->post(route('admin.product-exports.builder.preview'), [
            'selection_payload' => '{bad-json',
        ])->assertSessionHasErrors('selection');

        $this->post(route('admin.product-exports.builder.preview'), [
            'selection' => [$product->id => [0]],
        ])->assertOk()->assertSee('Legacy selected');
    }

    public function test_bulk_controls_and_single_field_submission_are_present_in_builder(): void
    {
        $blade = file_get_contents(resource_path('views/product-exports/builder.blade.php'));
        $script = file_get_contents(public_path('js/product-export-builder.js'));

        $this->assertStringContainsString('builderBulkSelect', $blade);
        $this->assertStringContainsString('builderBulkClear', $blade);
        $this->assertStringContainsString('builderBulkCancel', $blade);
        $this->assertStringContainsString('builderBulkStatus', $blade);
        $this->assertStringContainsString('selection_payload', $script);
        $this->assertStringContainsString('data-selection-batch-url', $blade);
    }
}
