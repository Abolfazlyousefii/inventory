<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuarantineZeroPriceVariantsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
    }

    public function test_zero_price_variant_only_disables_sales_and_keeps_price_stock_reserved(): void
    {
        $this->seedVariant(10, 1, 0, 5, 2, true, true, 3, 4);
        $beforeStock = DB::table('warehouse_stocks')->sum('quantity');

        $this->artisan('inventory:quarantine-zero-price-variants --apply --output=testing/zero-a')->assertExitCode(0);

        $variant = DB::table('product_variants')->where('id', 10)->first();
        $this->assertSame(0, (int) $variant->sell_price);
        $this->assertSame(5, (int) $variant->stock);
        $this->assertSame(2, (int) $variant->reserved);
        $this->assertSame(0, (int) $variant->sales_enabled);
        $this->assertSame($beforeStock, (int) DB::table('warehouse_stocks')->sum('quantity'));
    }

    public function test_dry_run_changes_nothing_and_second_apply_is_idempotent(): void
    {
        $this->seedVariant(20, 2, 0, 8, 1, true, true, 1, 0);

        $this->artisan('inventory:quarantine-zero-price-variants --output=testing/zero-b')->assertExitCode(0);
        $this->assertSame(1, (int) DB::table('product_variants')->where('id', 20)->value('sales_enabled'));

        $this->artisan('inventory:quarantine-zero-price-variants --apply --output=testing/zero-b1')->assertExitCode(0);
        $this->artisan('inventory:quarantine-zero-price-variants --apply --output=testing/zero-b2')->assertExitCode(0);
        $this->assertSame(0, (int) DB::table('product_variants')->where('id', 20)->value('sales_enabled'));
    }

    private function seedVariant(int $variantId, int $productId, int $price, int $stock, int $reserved, bool $active, bool $salesEnabled, int $central, int $branch): void
    {
        DB::table('products')->insertOrIgnore(['id' => $productId, 'name' => 'Product '.$productId]);
        DB::table('product_variants')->insert(['id' => $variantId, 'product_id' => $productId, 'variant_name' => 'Variant '.$variantId, 'variant_code' => 'V'.$variantId, 'sell_price' => $price, 'stock' => $stock, 'reserved' => $reserved, 'is_active' => $active, 'sales_enabled' => $salesEnabled, 'updated_at' => now()]);
        DB::table('warehouse_stocks')->insert(['warehouse_id' => 1, 'product_id' => $productId, 'product_variant_id' => $variantId, 'quantity' => $central]);
        DB::table('warehouse_stocks')->insert(['warehouse_id' => 2, 'product_id' => $productId, 'product_variant_id' => $variantId, 'quantity' => $branch]);
    }

    private function schema(): void
    {
        Schema::create('products', fn (Blueprint $t) => [$t->id(), $t->string('name')->nullable()]);
        Schema::create('product_variants', fn (Blueprint $t) => [$t->id(), $t->foreignId('product_id'), $t->string('variant_name')->nullable(), $t->string('variant_code')->nullable(), $t->bigInteger('sell_price')->default(0), $t->integer('stock')->default(0), $t->integer('reserved')->default(0), $t->boolean('is_active')->default(true), $t->boolean('sales_enabled')->default(true), $t->timestamp('updated_at')->nullable()]);
        Schema::create('warehouses', fn (Blueprint $t) => [$t->id(), $t->string('type')]);
        DB::table('warehouses')->insert([['id' => 1, 'type' => 'central'], ['id' => 2, 'type' => 'personnel']]);
        Schema::create('warehouse_stocks', fn (Blueprint $t) => [$t->id(), $t->foreignId('warehouse_id'), $t->foreignId('product_id'), $t->foreignId('product_variant_id'), $t->integer('quantity')->default(0)]);
    }
}
