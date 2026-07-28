<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairMissingOfficialReservationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->schema();
    }

    public function test_finance_queue_preinvoice_with_correct_reservation_stays_untouched(): void
    {
        $this->seedOrder(301, 'pending_finance', null, 10, 2, 2, 5);

        $this->artisan('inventory:repair-missing-official-reservations --order=301 --apply')->assertExitCode(0);

        $this->assertSame(1, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->count());
        $this->assertSame(2, (int) DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->sum('quantity'));
        $this->assertSame(5, (int) DB::table('product_variants')->where('id', 10)->value('reserved'));
    }

    public function test_preinvoice_with_invoice_or_stock_released_at_is_never_backfilled(): void
    {
        $this->seedOrder(301, 'pending_finance', null, 10, 3, 1, 5);
        DB::table('invoices')->insert(['id' => 1, 'preinvoice_order_id' => 301, 'status' => 'processing']);

        $this->artisan('inventory:repair-missing-official-reservations --order=301 --apply')->assertExitCode(1);
        $this->assertSame(1, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->count());

        $this->seedOrder(302, 'pending_finance', now(), 20, 3, 1, 5);
        $this->artisan('inventory:repair-missing-official-reservations --order=302 --apply')->assertExitCode(1);
        $this->assertSame(1, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 302)->count());
    }

    public function test_temporary_user_reservations_are_not_changed(): void
    {
        $this->seedOrder(301, 'pending_finance', null, 10, 3, 1, 5);
        DB::table('preinvoice_draft_reservations')->insert([
            'id' => 99,
            'token' => 'temp-token',
            'user_id' => 7,
            'preinvoice_order_id' => null,
            'product_id' => 1,
            'variant_id' => 10,
            'quantity' => 4,
            'reservation_scope' => 'temporary_online',
            'converted_at' => null,
            'released_at' => null,
            'release_reason' => null,
        ]);

        $this->artisan('inventory:repair-missing-official-reservations --order=301 --apply')->assertExitCode(0);

        $temporary = DB::table('preinvoice_draft_reservations')->where('id', 99)->first();
        $this->assertNull($temporary->preinvoice_order_id);
        $this->assertSame('temporary_online', $temporary->reservation_scope);
        $this->assertSame(4, (int) $temporary->quantity);
        $this->assertNull($temporary->converted_at);
        $this->assertNull($temporary->released_at);
        $this->assertSame(2, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->count());
    }

    public function test_dry_run_is_default_and_apply_is_idempotent(): void
    {
        $this->seedOrder(301, 'pending_finance', null, 10, 3, 1, 5);

        $this->artisan('inventory:repair-missing-official-reservations --order=301')->assertExitCode(0);
        $this->assertSame(1, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->count());

        $this->artisan('inventory:repair-missing-official-reservations --order=301 --apply')->assertExitCode(0);
        $this->artisan('inventory:repair-missing-official-reservations --order=301 --apply')->assertExitCode(0);

        $this->assertSame(2, DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->count());
        $this->assertSame(3, (int) DB::table('preinvoice_draft_reservations')->where('preinvoice_order_id', 301)->sum('quantity'));
    }

    private function seedOrder(int $orderId, string $status, mixed $releasedAt, int $variantId, int $required, int $official, int $cachedReserved): void
    {
        DB::table('products')->insertOrIgnore(['id' => 1, 'name' => 'Product', 'stock' => 50, 'reserved' => 0]);
        DB::table('product_variants')->insert(['id' => $variantId, 'product_id' => 1, 'variant_name' => 'Variant', 'stock' => 50, 'reserved' => $cachedReserved]);
        DB::table('warehouse_stocks')->insert(['product_id' => 1, 'product_variant_id' => $variantId, 'quantity' => 50]);
        DB::table('preinvoice_orders')->insert(['id' => $orderId, 'status' => $status, 'stock_released_at' => $releasedAt, 'total_price' => 1000]);
        DB::table('preinvoice_order_items')->insert(['preinvoice_order_id' => $orderId, 'product_id' => 1, 'variant_id' => $variantId, 'quantity' => $required, 'price' => 1000]);
        DB::table('preinvoice_draft_reservations')->insert([
            'token' => 'official-'.$orderId,
            'preinvoice_order_id' => $orderId,
            'product_id' => 1,
            'variant_id' => $variantId,
            'quantity' => $official,
            'reservation_scope' => 'official',
            'converted_at' => now(),
            'released_at' => null,
            'release_reason' => null,
        ]);
    }

    private function schema(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('reserved')->default(0);
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->string('variant_name')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('reserved')->default(0);
        });
        Schema::create('warehouse_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id');
            $table->integer('quantity')->default(0);
        });
        Schema::create('preinvoice_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->timestamp('stock_released_at')->nullable();
            $table->integer('total_price')->default(0);
        });
        Schema::create('preinvoice_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preinvoice_order_id');
            $table->foreignId('product_id');
            $table->foreignId('variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('price')->default(0);
        });
        Schema::create('preinvoice_draft_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('token');
            $table->foreignId('user_id')->nullable();
            $table->foreignId('preinvoice_order_id')->nullable();
            $table->foreignId('product_id');
            $table->foreignId('variant_id');
            $table->integer('quantity')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('browser_session_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable();
            $table->string('release_reason')->nullable();
            $table->text('release_note')->nullable();
            $table->string('reservation_scope')->nullable();
            $table->string('reservation_tier')->nullable();
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preinvoice_order_id')->nullable();
            $table->string('status')->nullable();
        });
    }
}
