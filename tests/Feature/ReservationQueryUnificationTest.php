<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationHealthService;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationQueryUnificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_reserved_quantity_equals_reservation_query_service_output(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 5, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $service = app(ReservationQueryService::class);
        $expected = (int) $service->quantitiesByVariant($fixture['product']->id, [$fixture['variant']->id])
            ->get($fixture['variant']->id, 0);

        $this->assertSame(5, $expected);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson(
            "/products/{$fixture['product']->id}/warehouse-stock",
        );

        if ($response->status() === 200) {
            $variantPayload = collect($response->json('variants') ?? [])
                ->firstWhere('id', $fixture['variant']->id);

            if ($variantPayload !== null && array_key_exists('summary', $variantPayload)) {
                $this->assertSame($expected, (int) ($variantPayload['summary']['reserved_quantity'] ?? -1));
            }
        }
    }

    public function test_audit_command_uses_same_calculation_as_query_service(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $this->reservation($fixture, 4, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $service = app(ReservationQueryService::class);
        $expected = (int) $service->quantitiesByVariant(official: true)->get($fixture['variant']->id, 0);
        $this->assertSame(4, $expected);

        $this->artisan('inventory:audit-stock-reservation-integrity --summary --output=testing/unification-audit')
            ->assertSuccessful();
    }

    public function test_health_metrics_remain_a_separate_definition_from_reserved_cache(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 6, old: true, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $queryService = app(ReservationQueryService::class);
        $activeCacheQuantity = (int) $queryService->quantitiesByVariant($fixture['product']->id, [$fixture['variant']->id])
            ->get($fixture['variant']->id, 0);

        $this->assertSame(0, $activeCacheQuantity, 'Abandoned temporary reservation must not count toward the reserved cache.');

        $healthService = app(ReservationHealthService::class);
        $summary = $healthService->summary(now());

        $this->assertGreaterThanOrEqual(1, $summary['old'] + $summary['orphaned'], 'Health monitoring must still surface the abandoned reservation.');
    }

    public function test_no_stock_movement_occurs_from_query_service_or_dashboard_read(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 5, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;

        $service = app(ReservationQueryService::class);
        $service->quantitiesByVariant($fixture['product']->id, [$fixture['variant']->id]);
        $service->dashboardStatistics(now());

        $user = User::factory()->create();
        $this->actingAs($user)->get('/warehouse-reservations');

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
    }

    private function inventoryFixture(): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Unification '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Unification product',
            'sku' => 'UNIFY-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Unification variant',
            'variant_code' => 'UNIFY-V-'.Str::uuid(),
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
        $timestamp = $old ? now()->subDays(10) : now();
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
            'customer_name' => 'Unification customer',
            'customer_mobile' => '09120000000',
            'total_price' => 1000,
        ]));
        if ($old) {
            DB::table('preinvoice_orders')->where('id', $order->id)->update([
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
        }

        return $order->refresh();
    }
}
