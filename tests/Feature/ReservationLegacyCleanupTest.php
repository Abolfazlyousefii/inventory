<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4-B — Safe Legacy Reservation Cleanup.
 *
 * These tests exercise the artisan command end-to-end (permission,
 * --confirm gate, --ids selection) rather than the service directly, since
 * the safety rules this phase adds (no --confirm => no writes; no --ids =>
 * report only) live in the command, not the service.
 */
class ReservationLegacyCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_candidate_reservation_cleanup_works(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$legacy->id}")
            ->assertSuccessful();

        $this->assertNotNull($legacy->fresh()->released_at);
        $this->assertSame('legacy_cleanup', $legacy->fresh()->release_reason);
    }

    public function test_official_preinvoice_reservation_cannot_be_cleaned(): void
    {
        $fixture = $this->inventoryFixture(6);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $official = $this->reservation($fixture, 6, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$official->id}")
            ->assertSuccessful();

        $this->assertNull($official->fresh()->released_at);
        $this->assertSame(0, ActivityLog::query()->where('action', 'legacy_reservation_cleanup')->count());
    }

    public function test_invoice_linked_reservation_cannot_be_cleaned(): void
    {
        $fixture = $this->inventoryFixture(4);
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: true);
        $consumed = $this->reservation($fixture, 4, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$consumed->id}")
            ->assertSuccessful();

        $this->assertNull($consumed->fresh()->released_at);
        $this->assertSame(0, ActivityLog::query()->where('action', 'legacy_reservation_cleanup')->count());
    }

    public function test_cleanup_does_not_change_warehouse_stock(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $warehouseQuantityBefore = $fixture['warehouseStock']->quantity;
        $movementCountBefore = DB::table('stock_movements')->count();

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$legacy->id}")
            ->assertSuccessful();

        $this->assertSame($warehouseQuantityBefore, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame($movementCountBefore, DB::table('stock_movements')->count());
    }

    public function test_reserved_cache_is_repaired(): void
    {
        $fixture = $this->inventoryFixture(63);
        $legacy = $this->reservation($fixture, 63, old: true);

        $this->assertSame(63, $fixture['product']->fresh()->reserved);
        $this->assertSame(63, $fixture['variant']->fresh()->reserved);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$legacy->id}")
            ->assertSuccessful();

        $this->assertSame(0, $fixture['product']->fresh()->reserved);
        $this->assertSame(0, $fixture['variant']->fresh()->reserved);
    }

    public function test_without_confirm_no_database_changes_happen(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $snapshot = $this->snapshot($fixture);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --ids={$legacy->id}")
            ->assertFailed();

        $this->assertSame($snapshot, $this->snapshot($fixture));
        $this->assertNull($legacy->fresh()->released_at);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_id_based_cleanup_only_processes_selected_reservations(): void
    {
        $fixture = $this->inventoryFixture(10);
        $legacyA = $this->reservation($fixture, 4, old: true);
        $legacyB = $this->reservation($fixture, 6, old: true);

        $this->artisan("inventory:cleanup-legacy-reservations --apply --confirm --ids={$legacyA->id}")
            ->assertSuccessful();

        $this->assertNotNull($legacyA->fresh()->released_at);
        $this->assertNull($legacyB->fresh()->released_at);
        $this->assertSame(1, ActivityLog::query()->where('action', 'legacy_reservation_cleanup')->count());
    }

    private function inventoryFixture(int $reserved): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Legacy cleanup phase4b '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Legacy cleanup phase4b product',
            'sku' => 'LEGACY4B-'.Str::uuid(),
            'stock' => 100,
            'reserved' => $reserved,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Legacy cleanup phase4b variant',
            'variant_code' => 'LEGACY4B-V-'.Str::uuid(),
            'stock' => 100,
            'reserved' => $reserved,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ]));
        $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
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
            'customer_name' => 'Legacy cleanup phase4b customer',
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

    private function snapshot(array $fixture): array
    {
        return [
            'reservation' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson(),
            'product' => $fixture['product']->fresh()->toJson(),
            'variant' => $fixture['variant']->fresh()->toJson(),
            'warehouse' => $fixture['warehouseStock']->fresh()->toJson(),
            'movements' => DB::table('stock_movements')->orderBy('id')->get()->toJson(),
        ];
    }
}
