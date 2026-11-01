<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Js;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ProductBundleManagementViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('variant_name');
            $t->string('variant_code');
            $t->integer('sell_price');
        });
        Schema::create('product_bundle_components', function (Blueprint $t) {
            $t->unsignedBigInteger('bundle_variant_id');
            $t->unsignedBigInteger('component_variant_id');
            $t->integer('quantity');
            $t->string('discount_type');
            $t->integer('discount_value');
            $t->timestamps();
        });
        Schema::create('bundle_assemblies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bundle_variant_id');
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('name');
        });
        Schema::create('warehouse_stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('product_variant_id');
            $t->integer('quantity');
        });
        config(['database.connections.site' => config('database.connections.sqlite')]);
        Schema::connection('site')->create('products', function (Blueprint $t) {
            $t->id();
            $t->boolean('published');
        });
        DB::table('products')->insert([['id' => 1, 'name' => 'Bundle'], ['id' => 2, 'name' => 'Component']]);
        DB::table('product_variants')->insert([
            ['id' => 10, 'product_id' => 1, 'variant_name' => 'Bundle variant', 'variant_code' => 'B1', 'sell_price' => 2000],
            ['id' => 20, 'product_id' => 2, 'variant_name' => 'Blue', 'variant_code' => 'C1', 'sell_price' => 1000],
        ]);
        DB::table('product_bundle_components')->insert(['bundle_variant_id' => 10, 'component_variant_id' => 20, 'quantity' => 2, 'discount_type' => 'percent', 'discount_value' => 5]);
        DB::table('warehouses')->insert(['id' => 1, 'type' => 'central', 'name' => 'انبار مرکزی']);
        DB::table('warehouse_stocks')->insert(['warehouse_id' => 1, 'product_variant_id' => 20, 'quantity' => 30]);
    }

    private function renderPanel(bool $bundle, ?array $old = null): string
    {
        $product = new Product;
        $product->forceFill(['id' => 1, 'is_bundle' => $bundle, 'bundle_stock_mode' => 'physical']);
        $product->setRelation('variants', $bundle ? ProductVariant::whereKey(10)->get() : collect());
        $this->withSession($old === null ? [] : ['_old_input' => ['components' => $old]]);
        request()->setLaravelSession(app('session')->driver());

        return view('products.partials.bundle-management', ['product' => $product, 'errors' => new ViewErrorBag])->render();
    }

    public function test_ordinary_product_panel_renders_without_old_component_input(): void
    {
        $this->assertStringContainsString('Bundle configuration', $this->renderPanel(false));
    }

    public function test_bundle_panel_preserves_saved_recipe_on_initial_visit(): void
    {
        $expected = [['id' => 20, 'name' => 'Component', 'variant' => 'Blue', 'code' => 'C1', 'quantity' => 2,
            'discount_type' => 'percent', 'discount_value' => 5, 'sell_price' => 1000, 'stock' => 30, 'allocated_quantity' => 0]];
        $this->assertStringContainsString('const initial = '.Js::from($expected).';', $this->renderPanel(true));
    }

    public function test_validation_redirect_restores_submitted_recipe_and_labels(): void
    {
        $old = [['variant_id' => 20, 'quantity' => 7, 'discount_type' => 'final', 'discount_value' => 90, 'allocated_quantity' => 3]];
        $expected = [['id' => 20, 'name' => 'Component', 'variant' => 'Blue', 'code' => 'C1', 'quantity' => 7,
            'discount_type' => 'final', 'discount_value' => 90, 'sell_price' => 1000, 'allocated_quantity' => 3]];
        $this->assertStringContainsString('const initial = '.Js::from($expected).';', $this->renderPanel(true, $old));
    }

    public function test_explicitly_empty_submission_does_not_restore_saved_components(): void
    {
        $this->assertStringContainsString('const initial = '.Js::from([]).';', $this->renderPanel(true, []));
    }
}
