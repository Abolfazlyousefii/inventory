<?php

namespace Tests\Feature;

use App\Events\SiteStockHoldChangeRequested;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteStockHold;
use App\Models\WarehouseStock;
use App\Services\SiteOrderStockHoldService;
use App\Services\SiteOrderRowLock;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * These assertions read warehouse balances and hold states independently of
 * the event listener, rather than mocking the new implementation.
 */
class SiteStockHoldEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private SiteOrderRowLock $siteOrderState;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config([
            'services.external_sync.token' => 'phase-two-test-token',
            'services.request_driven_site_stock' => false,
        ]);
        $this->createMissingSiteHoldTestTables();
        // The real row lock requires two MySQL schemas. The ordinary SQLite
        // tests substitute only the state reader; stock is still real SQLite.
        $this->siteOrderState = new class extends SiteOrderRowLock {
            public string $siteStatus = 'unpaid';

            public function status(int $siteOrderId): string
            {
                return $this->siteStatus;
            }
        };
        app()->instance(SiteOrderRowLock::class, $this->siteOrderState);
    }

    public function test_api_reservation_deducts_only_once_and_payment_confirmation_does_not_deduct_again(): void
    {
        [$product, $variant, $warehouseId] = $this->catalog(10);
        $request = [
            'site_order_id' => 81001,
            'expires_in_minutes' => 60,
            'lines' => [['variant_id' => $variant->id, 'quantity' => 3]],
        ];
        $first = $this->withHeader('X-CRM-Token', 'phase-two-test-token')
            ->postJson('/api/external/stock-holds', $request)->assertOk();
        $holdId = (int) $first->json('hold_id');

        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertNotNull(DB::table('site_stock_holds')->where('id', $holdId)->value('stock_applied_at'));
        $this->postJson('/api/external/stock-holds', $request)->assertOk()->assertJsonPath('hold_id', $holdId);
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));

        $this->siteOrderState->siteStatus = 'paid';
        $this->postJson('/api/external/stock-holds/81001/confirm', ['hold_id' => $holdId])
            ->assertOk()->assertJsonPath('status', 'payment_confirmed');
        $this->postJson('/api/external/stock-holds/81001/confirm', ['hold_id' => $holdId])->assertOk();
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));
        $this->postJson('/api/external/stock-holds/81001/release', ['hold_id' => $holdId])->assertUnprocessable();
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_api_refuses_release_after_site_becomes_paid_before_confirmation(): void
    {
        [, $variant, $warehouseId] = $this->catalog(9);
        $hold = app(SiteOrderStockHoldService::class)->reserve(82001, [
            ['variant_id' => $variant->id, 'quantity' => 3],
        ], 60);
        $this->siteOrderState->siteStatus = 'paid';

        $this->withHeader('X-CRM-Token', 'phase-two-test-token')
            ->postJson('/api/external/stock-holds/82001/release', ['hold_id' => (int) $hold->id])
            ->assertUnprocessable();
        $this->assertSame('held', $hold->fresh()->status);
        $this->assertSame(6, $this->quantity($warehouseId, (int) $variant->id));

        $this->postJson('/api/external/stock-holds/82001/confirm', ['hold_id' => (int) $hold->id])
            ->assertOk()->assertJsonPath('status', 'payment_confirmed');
        $this->assertSame(6, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_api_does_not_confirm_an_unpaid_or_cancelled_order(): void
    {
        [, $variant, $warehouseId] = $this->catalog(7);
        $hold = app(SiteOrderStockHoldService::class)->reserve(82002, [
            ['variant_id' => $variant->id, 'quantity' => 2],
        ], 60);
        $this->withHeader('X-CRM-Token', 'phase-two-test-token')
            ->postJson('/api/external/stock-holds/82002/confirm', ['hold_id' => (int) $hold->id])
            ->assertUnprocessable();
        $this->siteOrderState->siteStatus = 'canceled';
        $this->postJson('/api/external/stock-holds/82002/confirm', ['hold_id' => (int) $hold->id])
            ->assertUnprocessable();
        $this->assertSame('held', $hold->fresh()->status);
        $this->assertSame(5, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_api_rejects_reservation_when_site_order_is_cancelled(): void
    {
        [, $variant, $warehouseId] = $this->catalog(4);
        $this->siteOrderState->siteStatus = 'canceled';
        $this->withHeader('X-CRM-Token', 'phase-two-test-token')
            ->postJson('/api/external/stock-holds', [
                'site_order_id' => 82003,
                'expires_in_minutes' => 30,
                'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
            ])->assertUnprocessable();
        $this->assertSame(0, SiteStockHold::query()->where('site_order_id', 82003)->count());
        $this->assertSame(4, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_real_site_order_lock_fails_closed_without_cross_schema_mysql(): void
    {
        try {
            DB::transaction(fn () => (new SiteOrderRowLock())->status(82004));
            $this->fail('SQLite cannot safely lock Site and Inventory rows together.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('MySQL', $exception->getMessage());
        }
    }

    public function test_release_and_repeated_release_restore_exactly_once(): void
    {
        [, $variant, $warehouseId] = $this->catalog(12);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(81002, [['variant_id' => $variant->id, 'quantity' => 5]], 60);
        $this->assertSame(7, $this->quantity($warehouseId, (int) $variant->id));

        $service->release(81002, (int) $hold->id);
        $service->release(81002, (int) $hold->id);
        $this->assertSame(12, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertSame('released', $hold->fresh()->status);
        $this->assertNotNull(DB::table('site_stock_holds')->where('id', $hold->id)->value('stock_returned_at'));

        try {
            DB::transaction(fn () => event(new SiteStockHoldChangeRequested(
                (int) $hold->id, SiteStockHoldChangeRequested::RELEASE
            )));
            $this->fail('The return event must not restore the same hold twice.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(12, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_repeated_release_does_not_fail_during_a_later_stocktake_freeze(): void
    {
        [$product, $variant, $warehouseId] = $this->catalog(12);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(81009, [
            ['variant_id' => $variant->id, 'quantity' => 5],
        ], 60);
        $service->release(81009, (int) $hold->id);
        $this->assertSame(12, $this->quantity($warehouseId, (int) $variant->id));

        // A delayed duplicate release must be a no-op even if stocktake started
        // after the original successful release. New mutations stay frozen.
        DB::table('inventory_count_freezes')->updateOrInsert(['id' => 1], [
            'enabled' => true,
            'all_products' => false,
            'product_ids' => json_encode([(int) $product->id]),
            'category_ids' => '[]',
            'updated_at' => now(),
        ]);
        $service->release(81009, (int) $hold->id);

        $this->assertSame('released', $hold->fresh()->status);
        $this->assertSame(12, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_distinct_reserve_event_objects_cannot_double_deduct(): void
    {
        [, $variant, $warehouseId] = $this->catalog(8);
        $hold = app(SiteOrderStockHoldService::class)->reserve(81003, [
            ['variant_id' => $variant->id, 'quantity' => 2],
        ], 60);
        try {
            DB::transaction(fn () => event(new SiteStockHoldChangeRequested(
                (int) $hold->id, SiteStockHoldChangeRequested::RESERVE
            )));
            $this->fail('An already applied reservation must not change stock twice.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(6, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_insufficient_stock_rolls_back_hold_and_stock_together(): void
    {
        [, $variant, $warehouseId] = $this->catalog(4);
        try {
            app(SiteOrderStockHoldService::class)->reserve(81004, [
                ['variant_id' => $variant->id, 'quantity' => 5],
            ], 60);
            $this->fail('Insufficient stock should reject the reservation.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(4, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertSame(0, SiteStockHold::query()->where('site_order_id', 81004)->count());
    }

    public function test_outer_transaction_failure_rolls_back_reservation_and_marker(): void
    {
        [, $variant, $warehouseId] = $this->catalog(9);
        try {
            DB::transaction(function () use ($variant): void {
                app(SiteOrderStockHoldService::class)->reserve(81005, [
                    ['variant_id' => $variant->id, 'quantity' => 4],
                ], 60);
                throw new RuntimeException('Deliberate rollback after hold reserve');
            });
            $this->fail('The source transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate rollback after hold reserve', $exception->getMessage());
        }
        $this->assertSame(9, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertSame(0, SiteStockHold::query()->where('site_order_id', 81005)->count());
    }

    public function test_consuming_a_paid_hold_creates_no_second_stock_change(): void
    {
        [, $variant, $warehouseId] = $this->catalog(10);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(81009, [['variant_id' => $variant->id, 'quantity' => 2]], 60);
        $service->confirm(81009, (int) $hold->id);
        $this->assertSame(8, $this->quantity($warehouseId, (int) $variant->id));

        // The consumer needs the invoice ID, not a second stock mutation.
        // A full paid-order import is exercised by the separate importer tests.
        $invoice = new Invoice();
        $invoice->id = 123456;
        $physicalItems = [['variant' => $variant, 'quantity' => 2]];
        DB::transaction(function () use ($service, $hold, $invoice, $physicalItems): void {
            $service->consumeForPaidInvoice(81009, (int) $hold->id, $physicalItems, $invoice);
            $service->consumeForPaidInvoice(81009, (int) $hold->id, $physicalItems, $invoice);
        });
        $this->assertSame('consumed', $hold->fresh()->status);
        $this->assertSame(8, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_release_expiration_restores_stock_but_consumption_does_not(): void
    {
        [, $variant, $warehouseId] = $this->catalog(10);
        $service = app(SiteOrderStockHoldService::class);
        $hold = $service->reserve(81006, [['variant_id' => $variant->id, 'quantity' => 2]], 1);
        $this->assertSame(8, $this->quantity($warehouseId, (int) $variant->id));
        $service->release(81006, (int) $hold->id, true);
        $this->assertSame('expired', $hold->fresh()->status);
        $this->assertSame(10, $this->quantity($warehouseId, (int) $variant->id));
    }

    public function test_automatic_bundle_protected_components_do_not_change_warehouse_twice(): void
    {
        [$component, $componentVariant, $warehouseId] = $this->catalog(6);
        $category = Category::query()->firstOrFail();
        $bundleProduct = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Protected bundle', 'sku' => 'KIT-PHASE2',
            'price' => 1000, 'stock' => 0,
            'is_bundle' => true, 'bundle_stock_mode' => 'automatic', 'is_sellable' => true,
        ]);
        $bundleVariant = ProductVariant::query()->create([
            'product_id' => $bundleProduct->id, 'variant_name' => 'Bundle',
            'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true,
        ]);
        DB::table('product_bundle_components')->insert([
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => 2,
        ]);
        DB::table('bundle_component_allocations')->insert([
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'remaining' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(SiteOrderStockHoldService::class);
        // 2 protected units are consumed from allocation; 1 ordinary component
        // is consumed from warehouse. Both come from the same physical component.
        $hold = $service->reserve(81007, [
            ['variant_id' => $bundleVariant->id, 'quantity' => 1],
            ['variant_id' => $componentVariant->id, 'quantity' => 1],
        ], 60);
        $this->assertSame(5, $this->quantity($warehouseId, (int) $componentVariant->id));
        $this->assertSame(2, (int) DB::table('bundle_component_allocations')
            ->where('bundle_variant_id', $bundleVariant->id)->value('remaining'));
        $service->release(81007, (int) $hold->id);
        $this->assertSame(6, $this->quantity($warehouseId, (int) $componentVariant->id));
        $this->assertSame(4, (int) DB::table('bundle_component_allocations')
            ->where('bundle_variant_id', $bundleVariant->id)->value('remaining'));
    }

    public function test_missing_event_listener_fails_closed_and_rolls_back_hold(): void
    {
        [, $variant, $warehouseId] = $this->catalog(5);
        Event::forget(SiteStockHoldChangeRequested::class);
        try {
            app(SiteOrderStockHoldService::class)->reserve(81008, [
                ['variant_id' => $variant->id, 'quantity' => 1],
            ], 60);
            $this->fail('A reservation must not commit when the stock listener is absent.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('no synchronous stock listener', $exception->getMessage());
        }
        $this->assertSame(5, $this->quantity($warehouseId, (int) $variant->id));
        $this->assertSame(0, SiteStockHold::query()->where('site_order_id', 81008)->count());
    }

    private function catalog(int $quantity): array
    {
        $category = Category::query()->create(['name' => 'Phase two']);
        $product = Product::query()->create([
            'category_id' => $category->id, 'name' => 'Physical item',
            'sku' => 'PHASE2-SKU', 'price' => 1000, 'stock' => $quantity,
            'is_sellable' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Color',
            'variant_code' => 'PHASE2-V', 'sell_price' => 1000,
            'stock' => $quantity, 'is_active' => true, 'sales_enabled' => true,
        ]);
        $warehouseId = WarehouseStockService::centralWarehouseId();
        WarehouseStock::query()->create([
            'warehouse_id' => $warehouseId, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'quantity' => $quantity,
        ]);
        return [$product, $variant, $warehouseId];
    }

    private function quantity(int $warehouseId, int $variantId): int
    {
        return (int) WarehouseStock::query()->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)->value('quantity');
    }

    private function createMissingSiteHoldTestTables(): void
    {
        if (! Schema::hasTable('site_stock_holds')) {
            Schema::create('site_stock_holds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_order_id')->unique();
                $table->string('fingerprint', 64);
                $table->string('status', 32);
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->json('bundle_allocated_lines')->nullable();
                $table->json('bundle_shared_allocations')->nullable();
                $table->timestamp('stock_applied_at')->nullable();
                $table->timestamp('stock_returned_at')->nullable();
                $table->timestamps();
            });
        }
        foreach (['bundle_allocated_lines', 'bundle_shared_allocations', 'stock_applied_at', 'stock_returned_at'] as $column) {
            if (! Schema::hasColumn('site_stock_holds', $column)) {
                Schema::table('site_stock_holds', function (Blueprint $table) use ($column): void {
                    if (str_ends_with($column, '_at')) {
                        $table->timestamp($column)->nullable();
                    } else {
                        $table->json($column)->nullable();
                    }
                });
            }
        }
        if (! Schema::hasTable('site_stock_hold_lines')) {
            Schema::create('site_stock_hold_lines', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('hold_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id');
                $table->unsignedInteger('quantity');
            });
        }
        if (! Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('quantity');
            });
        }
        if (! Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('remaining');
                $table->timestamps();
            });
        }
    }
}
