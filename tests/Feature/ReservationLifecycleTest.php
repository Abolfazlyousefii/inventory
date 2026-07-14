<?php

namespace Tests\Feature;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\PreinvoiceReservationService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_is_idempotent_and_does_not_inflate_stock(): void
    {
        [$order, $reservation, $variant] = $this->officialReservation(100, 20);

        $this->assertStock($variant->id, 80, 20, 100);

        $service = app(PreinvoiceReservationService::class);
        $service->releaseReservation($reservation, $order->creator, 'test_release');
        $this->assertStock($variant->id, 100, 0, 100);

        $service->releaseReservation($reservation->refresh(), $order->creator, 'test_release_again');
        $this->assertStock($variant->id, 100, 0, 100);
    }

    public function test_consume_does_not_decrement_free_stock_again(): void
    {
        [$order, $_reservation, $variant] = $this->officialReservation(100, 20);

        app(PreinvoiceReservationService::class)->consumeOfficialReservationsForOrder($order, $order->creator);

        $this->assertStock($variant->id, 80, 0, 80);
    }

    private function officialReservation(int $initial, int $reserved): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create(['name' => 'کالا', 'is_sellable' => true, 'stock' => $initial, 'reserved' => $reserved]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع', 'variant_code' => 'T-1', 'sell_price' => 1000, 'stock' => $initial - $reserved, 'reserved' => $reserved, 'is_active' => true, 'sales_enabled' => true]);
        WarehouseStock::query()->create(['warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => $initial - $reserved]);
        $order = PreinvoiceOrder::query()->create(['uuid' => uniqid('PI-'), 'created_by' => $user->id, 'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE, 'customer_name' => 'مشتری', 'total_price' => 1000]);
        $reservation = PreinvoiceDraftReservation::query()->create(['token' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'preinvoice_order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $reserved, 'converted_at' => now(), 'reservation_scope' => 'official']);

        return [$order->load('creator'), $reservation, $variant];
    }

    private function assertStock(int $variantId, int $free, int $reserved, int $total): void
    {
        $variant = ProductVariant::query()->findOrFail($variantId);
        $activeReserved = (int) PreinvoiceDraftReservation::query()->where('variant_id', $variantId)->where('reservation_scope', 'official')->whereNull('released_at')->whereNull('release_reason')->sum('quantity');
        $this->assertSame($free, (int) $variant->stock);
        $this->assertSame($reserved, (int) $variant->reserved);
        $this->assertSame($reserved, $activeReserved);
        $this->assertSame($total, (int) $variant->stock + $activeReserved);
    }
}
