<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairReservedCacheCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
    }

    public function test_expected_reserved_excludes_official_but_keeps_active_temporary_and_preinvoice_301(): void
    {
        $this->seedVariant(10, 1, 99);
        $this->seedOrder(301, 'pending_finance', null, 10, 3);
        $this->seedReservation(1, 301, 10, 3, 'official', now());
        $this->seedReservation(2, null, 10, 4, 'temporary_online', null);

        $this->artisan('inventory:repair-reserved-cache --apply --output=testing/reserved-a')->assertExitCode(0);

        $this->assertSame(7, (int) DB::table('product_variants')->where('id', 10)->value('reserved'));
        $this->assertSame(7, (int) DB::table('products')->where('id', 1)->value('reserved'));
        $this->assertSame(1, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->where('reservation_scope', 'official')->count());
        $this->assertSame(4, (int) DB::table('preinvoice_draft_reservations')->where('id', 2)->value('quantity'));
    }

    public function test_cancelled_by_finance_unreleased_variants_are_excluded_and_warehouse_stocks_do_not_change(): void
    {
        $this->seedVariant(20, 2, 11);
        $this->seedOrder(400, 'cancelled_by_finance', null, 20, 5);
        $beforeWarehouse = DB::table('warehouse_stocks')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('inventory:repair-reserved-cache --apply --output=testing/reserved-b')->assertExitCode(0);

        $this->assertSame(11, (int) DB::table('product_variants')->where('id', 20)->value('reserved'));
        $this->assertEquals($beforeWarehouse, DB::table('warehouse_stocks')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_dry_run_changes_nothing_and_apply_is_idempotent(): void
    {
        $this->seedVariant(30, 3, 20);
        $this->seedOrder(500, 'finance_reviewing', null, 30, 6);

        $this->artisan('inventory:repair-reserved-cache --output=testing/reserved-c')->assertExitCode(0);
        $this->assertSame(20, (int) DB::table('product_variants')->where('id', 30)->value('reserved'));

        $this->artisan('inventory:repair-reserved-cache --apply --output=testing/reserved-c1')->assertExitCode(0);
        $this->assertSame(6, (int) DB::table('product_variants')->where('id', 30)->value('reserved'));

        $this->artisan('inventory:repair-reserved-cache --apply --output=testing/reserved-c2')->assertExitCode(0);
        $this->assertSame(6, (int) DB::table('product_variants')->where('id', 30)->value('reserved'));
    }

    private function seedVariant(int $variantId, int $productId, int $reserved): void
    {
        DB::table('products')->insertOrIgnore(['id' => $productId, 'name' => 'Product '.$productId, 'stock' => 50, 'reserved' => 0]);
        DB::table('product_variants')->insert(['id' => $variantId, 'product_id' => $productId, 'variant_name' => 'Variant '.$variantId, 'variant_code' => 'V'.$variantId, 'stock' => 50, 'reserved' => $reserved, 'updated_at' => now()]);
        DB::table('warehouse_stocks')->insert(['id' => $variantId, 'warehouse_id' => 1, 'product_id' => $productId, 'product_variant_id' => $variantId, 'quantity' => 50]);
    }

    private function seedOrder(int $orderId, string $status, mixed $releasedAt, int $variantId, int $quantity): void
    {
        $productId = (int) DB::table('product_variants')->where('id', $variantId)->value('product_id');
        DB::table('preinvoice_orders')->insert(['id' => $orderId, 'uuid' => 'order-'.$orderId, 'status' => $status, 'stock_released_at' => $releasedAt]);
        DB::table('preinvoice_order_items')->insert(['preinvoice_order_id' => $orderId, 'product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity]);
    }

    private function seedReservation(int $id, ?int $orderId, int $variantId, int $quantity, string $scope, mixed $convertedAt): void
    {
        $productId = (int) DB::table('product_variants')->where('id', $variantId)->value('product_id');
        DB::table('preinvoice_draft_reservations')->insert(['id' => $id, 'token' => 'token-'.$id, 'preinvoice_order_id' => $orderId, 'product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity, 'reservation_scope' => $scope, 'converted_at' => $convertedAt, 'released_at' => null, 'release_reason' => null]);
    }

    private function schema(): void
    {
        Schema::create('products', fn (Blueprint $t) => [$t->id(), $t->string('name')->nullable(), $t->integer('stock')->default(0), $t->integer('reserved')->default(0), $t->timestamp('updated_at')->nullable()]);
        Schema::create('product_variants', fn (Blueprint $t) => [$t->id(), $t->foreignId('product_id'), $t->string('variant_name')->nullable(), $t->string('variant_code')->nullable(), $t->integer('stock')->default(0), $t->integer('reserved')->default(0), $t->timestamp('updated_at')->nullable()]);
        Schema::create('warehouses', fn (Blueprint $t) => [$t->id(), $t->string('type')]);
        DB::table('warehouses')->insert(['id' => 1, 'type' => 'central']);
        Schema::create('warehouse_stocks', fn (Blueprint $t) => [$t->id(), $t->foreignId('warehouse_id')->nullable(), $t->foreignId('product_id'), $t->foreignId('product_variant_id'), $t->integer('quantity')->default(0)]);
        Schema::create('preinvoice_orders', fn (Blueprint $t) => [$t->id(), $t->string('uuid')->nullable(), $t->string('status'), $t->timestamp('stock_released_at')->nullable()]);
        Schema::create('preinvoice_order_items', fn (Blueprint $t) => [$t->id(), $t->foreignId('preinvoice_order_id'), $t->foreignId('product_id'), $t->foreignId('variant_id')->nullable(), $t->integer('quantity')->default(0)]);
        Schema::create('preinvoice_draft_reservations', fn (Blueprint $t) => [$t->id(), $t->string('token'), $t->foreignId('preinvoice_order_id')->nullable(), $t->foreignId('product_id'), $t->foreignId('variant_id'), $t->integer('quantity')->default(0), $t->string('reservation_scope')->nullable(), $t->timestamp('converted_at')->nullable(), $t->timestamp('released_at')->nullable(), $t->string('release_reason')->nullable()]);
        Schema::create('invoices', fn (Blueprint $t) => [$t->id(), $t->foreignId('preinvoice_order_id')->nullable()]);
    }
}
