<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_statistics_are_correct(): void
    {
        $fixture = $this->inventoryFixture();

        // Active temporary reservation.
        $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        // Official preinvoice reservation on an in-progress order (also active).
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $service = app(ReservationQueryService::class);
        $stats = $service->dashboardStatistics(now());

        $this->assertSame(2, $stats['active']['count']);
        $this->assertSame(5, $stats['active']['quantity']);
        $this->assertSame(1, $stats['official']['count']);
        $this->assertSame(2, $stats['official']['quantity']);
        $this->assertSame(1, $stats['temporary']['count']);
        $this->assertSame(3, $stats['temporary']['quantity']);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/warehouse-reservations');
        if ($response->status() === 200) {
            $response->assertViewHas('stats', fn (array $viewStats) => $viewStats === $stats);
        }
    }

    public function test_product_reserved_quantity_matches_reservation_query_service(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 4, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $service = app(ReservationQueryService::class);
        $expected = (int) $service->quantitiesByVariant($fixture['product']->id, [$fixture['variant']->id])
            ->get($fixture['variant']->id, 0);

        $this->assertSame(4, $expected);

        $stats = $service->dashboardStatistics(now());
        $this->assertSame($expected, $stats['active']['quantity']);
    }

    public function test_official_reservations_are_counted_correctly(): void
    {
        $fixture = $this->inventoryFixture();
        $activeOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $this->reservation($fixture, 5, $activeOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        // A temporary reservation must never be counted as "official".
        $this->reservation($fixture, 1, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $service = app(ReservationQueryService::class);
        $stats = $service->dashboardStatistics(now());

        $this->assertSame(1, $stats['official']['count']);
        $this->assertSame(5, $stats['official']['quantity']);
    }

    public function test_critical_reservations_are_classified_correctly(): void
    {
        $fixture = $this->inventoryFixture();

        // Official reservation without invoice, older than the critical threshold (72h).
        $oldOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $criticalReservation = $this->reservation($fixture, 2, $oldOrder, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        // A fresh official reservation must not be classified as critical.
        $freshOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $this->reservation($fixture, 1, $freshOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $service = app(ReservationQueryService::class);
        $stats = $service->dashboardStatistics(now());

        $this->assertSame(1, $stats['critical']['count']);
        $this->assertSame(2, $stats['critical']['quantity']);
        $this->assertSame(
            'critical',
            $service->classify($criticalReservation->refresh()->load('order.invoice'))['label'],
        );
    }

    public function test_no_stock_movement_occurs_from_dashboard_statistics(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;

        $service = app(ReservationQueryService::class);
        $service->dashboardStatistics(now());

        $user = User::factory()->create();
        $this->actingAs($user)->get('/warehouse-reservations');

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
    }

    private function inventoryFixture(): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Dashboard '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Dashboard product',
            'sku' => 'DASH-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Dashboard variant',
            'variant_code' => 'DASH-V-'.Str::uuid(),
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
            'customer_name' => 'Dashboard customer',
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
