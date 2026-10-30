<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeCategoryPhysicalProductsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        // Match the nullable production draft schema; recovered SQLite legacy
        // migrations still declare the original non-null count column.
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) =>
                $table->integer('actual_quantity')->nullable()->change()
        );
        $user = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $root = Category::withoutEvents(fn () => Category::create(['name'=>'Bulk '.Str::random(8)]));
        $child = Category::withoutEvents(fn () => Category::create(['name'=>'Child '.Str::random(8), 'parent_id'=>$root->id]));
        $outside = Category::withoutEvents(fn () => Category::create(['name'=>'Outside '.Str::random(8)]));
        $warehouse = Warehouse::where('type', 'central')->first()
            ?? Warehouse::withoutEvents(fn () => Warehouse::create(['name'=>'انبار مرکزی', 'type'=>'central', 'is_active'=>true]));
        $make = function (Category $category) use ($warehouse): array {
            $product = Product::withoutEvents(fn () => Product::create([
                'name'=>'Bulk product', 'category_id'=>$category->id, 'sku'=>Str::random(12),
                'stock'=>8, 'price'=>150000, 'is_sellable'=>false,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id'=>$product->id, 'variant_name'=>'Black', 'variant_code'=>Str::random(12),
                'stock'=>8, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id'=>$warehouse->id, 'product_id'=>$product->id,
                'product_variant_id'=>$variant->id, 'quantity'=>8,
            ]));
            return [$product, $variant];
        };
        return [$root, $make($root), $make($child), $make($outside)];
    }

    public function test_category_pagination_excludes_virtual_automatic_bundles_and_retains_physical_and_inactive_products(): void
    {
        [$root, $automatic, $physical, $ordinary] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('products', function (\Illuminate\Database\Schema\Blueprint $table) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('products', 'is_bundle')) $table->boolean('is_bundle')->default(false);
            if (! \Illuminate\Support\Facades\Schema::hasColumn('products', 'bundle_stock_mode')) $table->string('bundle_stock_mode')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('products')->where('id', $automatic[0]->id)
            ->update(['is_bundle'=>true, 'bundle_stock_mode'=>'automatic']);
        \Illuminate\Support\Facades\DB::table('products')->where('id', $physical[0]->id)
            ->update(['is_bundle'=>true, 'bundle_stock_mode'=>'physical']);
        \Illuminate\Support\Facades\DB::table('products')->where('id', $ordinary[0]->id)
            ->update(['category_id'=>$root->id, 'is_bundle'=>false, 'bundle_stock_mode'=>null]);
        $firstPage = $this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$root->id, 'limit'=>1,
        ]))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('has_more', true)
            ->assertJsonPath('rows.0.product_id', $physical[0]->id);
        $this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$root->id, 'limit'=>1, 'after_id'=>$firstPage->json('next_cursor'),
        ]))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('has_more', false)
            ->assertJsonPath('rows.0.product_id', $ordinary[0]->id)->assertJsonPath('rows.0.sales_enabled', false);
        $this->getJson(route('stock-count-documents.category-variants', [
            'category_id'=>$root->id, 'q'=>$automatic[1]->variant_code,
        ]))->assertOk()->assertJsonCount(0, 'rows')->assertJsonPath('has_more', false);
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::where('quantity', '<>', 8)->count());
    }
}
