<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationClassificationService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $classification = app(ReservationClassificationService::class)->classify($reservation);

        $this->assertSame('temporary', $classification['type']);
        $this->assertSame('active', $classification['lifecycle']);
        $this->assertSame('temporary_active', $classification['label']);
        $this->assertSame('temporary_active', $classification['state']);
        $this->assertSame('keep_active', $classification['recommended_action']);
        $this->assertFalse($classification['would_change_warehouse_stock']);
    }

    public function test_official_preinvoice_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $reservation = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL)
            ->load('order.invoice');

        $classification = app(ReservationClassificationService::class)->classify($reservation);

        $this->assertSame('official', $classification['type']);
        $this->assertSame('active', $classification['lifecycle']);
        $this->assertSame('official_active', $classification['state']);
    }

    public function test_critical_age_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $reservation = $this->reservation($fixture, 4, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL)
            ->load('order.invoice');

        $service = app(ReservationClassificationService::class);
        $classification = $service->classify($reservation);

        $this->assertSame('critical', $classification['health']);
        $this->assertSame('official_active', $classification['state']);
    }

    public function test_consumed_reservation_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: false);
        $reservation = $this->reservation($fixture, 1, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $reservation->forceFill(['converted_at' => now()])->save();
        $reservation->refresh()->load('order.invoice');

        $classification = app(ReservationClassificationService::class)->classify($reservation);

        $this->assertSame('consumed', $classification['lifecycle']);
        $this->assertSame('consumed', $classification['label']);
    }

    public function test_legacy_candidate_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, old: true);
        $reservation = $this->reservation($fixture, 5, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $reservation->load('order.invoice', 'activeDrafts');

        $classification = app(ReservationClassificationService::class)->classify($reservation, now());

        $this->assertSame('legacy_safe', $classification['state']);
        $this->assertSame('legacy_cleanup_candidate', $classification['recommended_action']);
        $this->assertFalse($classification['would_change_warehouse_stock']);
    }

    public function test_old_null_preinvoice_is_historical_ambiguous_not_legacy_safe(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 5, old: true, scope: null)->load('order.invoice', 'activeDrafts');

        $classification = app(ReservationClassificationService::class)->classify($reservation, now());

        $this->assertSame('historical_ambiguous', $classification['state']);
        $this->assertSame('manual_review', $classification['recommended_action']);
        $this->assertFalse($classification['would_change_warehouse_stock']);
    }

    public function test_missing_preinvoice_relation_is_historical_ambiguous(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, old: true);
        $reservation = $this->reservation($fixture, 5, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $reservation->setRelation('order', null)->setRelation('activeDrafts', collect());

        $classification = app(ReservationClassificationService::class)->classify($reservation, now());

        $this->assertSame('historical_ambiguous', $classification['state']);
        $this->assertSame('missing_official_relation', $classification['reason']);
    }

    public function test_stale_temporary_below_legacy_boundary_is_releasable(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $at = now();
        $reservation->forceFill(['last_seen_at' => $at->copy()->subMinutes(10), 'expires_at' => $at->copy()->subMinute()])->save();

        $classification = app(ReservationClassificationService::class)->classify($reservation->fresh()->load('order.invoice', 'activeDrafts'), $at);

        $this->assertSame('temporary_stale_releasable', $classification['state']);
        $this->assertSame('normal_release_candidate', $classification['recommended_action']);
        $this->assertTrue($classification['would_change_warehouse_stock']);
    }

    public function test_active_draft_token_protects_old_temporary_reservation(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 2, old: true, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $draft = $this->order(PreinvoiceOrder::STATUS_DRAFT, old: true);
        $draft->forceFill(['draft_token' => $reservation->token])->save();

        $classification = app(ReservationClassificationService::class)->classify($reservation->fresh()->load('order.invoice', 'activeDrafts'), now());

        $this->assertSame('active_valid', $classification['state']);
        $this->assertSame('active_draft_owns_token', $classification['reason']);
    }

    public function test_no_stock_movement_happens_from_classification(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;

        $service = app(ReservationClassificationService::class);
        $service->classify($reservation);
        $service->classify($reservation);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/warehouse-reservations');

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertNull($reservation->fresh()->released_at);
    }

    private function inventoryFixture(): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Classification '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Classification product',
            'sku' => 'CLASSIFY-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Classification variant',
            'variant_code' => 'CLASSIFY-V-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ]));
        $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 20,
        ]));

        return compact('product', 'variant', 'warehouseStock');
    }

    private function reservation(
        array $fixture,
        int $quantity,
        ?PreinvoiceOrder $order = null,
        bool $old = true,
        ?string $scope = null,
    ): PreinvoiceDraftReservation {
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'preinvoice_order_id' => $order?->id,
            'product_id' => $fixture['product']->id,
            'variant_id' => $fixture['variant']->id,
            'quantity' => $quantity,
            'reservation_scope' => $scope,
            'last_seen_at' => $old ? now()->subDays(10) : now(),
            'expires_at' => $old ? now()->subDays(10) : now()->addMinutes(10),
        ]);
        $timestamp = $old ? now()->subDays(4) : now();
        DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $reservation->refresh();
    }

    private function order(string $status, bool $old): PreinvoiceOrder
    {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'seller_id' => $user->id,
            'document_date' => now(),
            'status' => $status,
            'customer_name' => 'Classification customer',
            'customer_mobile' => '09120000000',
            'total_price' => 1000,
        ]));
        if ($old) {
            DB::table('preinvoice_orders')->where('id', $order->id)->update([
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ]);
        }

        return $order->refresh();
    }
}
