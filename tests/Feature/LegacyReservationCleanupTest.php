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

class LegacyReservationCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_legacy_reservations_without_changing_data(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $before = $this->snapshot($fixture);

        $this->artisan('inventory:cleanup-legacy-reservations --dry-run')
            ->expectsOutputToContain('Legacy reservations found: 1')
            ->expectsOutputToContain('Total quantity: 5')
            ->expectsOutputToContain($reservation->token)
            ->expectsOutputToContain('No data changed')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertSame(0, ActivityLog::query()->where('action', 'reservation_legacy_cleanup')->where('subject_id', $reservation->id)->count());
    }

    public function test_apply_closes_legacy_lifecycle_repairs_cache_and_never_returns_stock(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $warehouseQuantity = $fixture['warehouseStock']->quantity;
        $movementCount = DB::table('stock_movements')->count();

        $this->artisan('inventory:cleanup-legacy-reservations --apply')
            ->expectsOutputToContain('Legacy reservations cleaned: 1')
            ->assertSuccessful();

        $reservation->refresh();
        $this->assertNotNull($reservation->released_at);
        $this->assertSame('legacy_cleanup', $reservation->release_reason);
        $this->assertSame('Legacy reservation cleanup without stock return', $reservation->release_note);
        $this->assertSame(0, $fixture['product']->fresh()->reserved);
        $this->assertSame(0, $fixture['variant']->fresh()->reserved);
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());

        $activity = ActivityLog::query()->where('action', 'reservation_legacy_cleanup')->sole();
        $this->assertSame($reservation->id, $activity->properties['reservation_id']);
        $this->assertSame($fixture['product']->id, $activity->properties['product_id']);
        $this->assertSame($fixture['variant']->id, $activity->properties['variant_id']);
        $this->assertSame(5, $activity->properties['quantity']);
        $this->assertSame('legacy_without_preinvoice', $activity->properties['reason']);
        $this->assertNull($activity->properties['old_state']['released_at']);
    }

    public function test_reservation_with_invoice_is_protected(): void
    {
        $fixture = $this->inventoryFixture(4);
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: true);
        $reservation = $this->reservation($fixture, 4, $order, old: true);
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);

        $this->artisan('inventory:cleanup-legacy-reservations --apply')->assertSuccessful();

        $this->assertNull($reservation->fresh()->released_at);
        $this->assertSame(4, $fixture['variant']->fresh()->reserved);
        $this->assertSame(0, ActivityLog::query()->where('action', 'reservation_legacy_cleanup')->where('subject_id', $reservation->id)->count());
    }

    public function test_active_preinvoice_reservation_is_protected_even_when_old(): void
    {
        $fixture = $this->inventoryFixture(6);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $reservation = $this->reservation($fixture, 6, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $this->artisan('inventory:cleanup-legacy-reservations --apply')->assertSuccessful();

        $this->assertNull($reservation->fresh()->released_at);
        $this->assertSame(6, $fixture['variant']->fresh()->reserved);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_duplicate_execution_is_idempotent(): void
    {
        $fixture = $this->inventoryFixture(3);
        $reservation = $this->reservation($fixture, 3, old: true);

        $this->artisan('inventory:cleanup-legacy-reservations --apply')->assertSuccessful();
        $releasedAt = $reservation->fresh()->released_at;
        $this->artisan('inventory:cleanup-legacy-reservations --apply')
            ->expectsOutputToContain('Legacy reservations cleaned: 0')
            ->assertSuccessful();

        $this->assertTrue($releasedAt->equalTo($reservation->fresh()->released_at));
        $this->assertSame(1, ActivityLog::query()->where('action', 'reservation_legacy_cleanup')->count());
    }

    public function test_reserved_cache_and_repair_command_use_only_real_active_reservations(): void
    {
        $fixture = $this->inventoryFixture(10);
        $this->reservation($fixture, 5, old: true);
        $activeOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $this->reservation($fixture, 3, $activeOrder, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->artisan('inventory:cleanup-legacy-reservations --apply')->assertSuccessful();
        $this->assertSame(5, $fixture['variant']->fresh()->reserved);
        $this->assertSame(5, $fixture['product']->fresh()->reserved);

        $this->artisan('inventory:repair-reserved-cache --apply --output=testing/legacy-cache')->assertSuccessful();
        $this->assertSame(5, $fixture['variant']->fresh()->reserved);
        $this->assertSame(5, $fixture['product']->fresh()->reserved);
    }

    public function test_integrity_audit_reports_cleanup_candidates_read_only(): void
    {
        $fixture = $this->inventoryFixture(7);
        $reservation = $this->reservation($fixture, 7, old: true);
        $before = $this->snapshot($fixture);

        $this->artisan('inventory:audit-legacy-reservation-integrity --stale-hours=72 --output=testing/legacy-audit')
            ->expectsOutputToContain('cleanup_legacy_rows_total')
            ->expectsOutputToContain((string) $reservation->id)
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot($fixture));
    }

    private function inventoryFixture(int $reserved): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Legacy cleanup '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Legacy cleanup product',
            'sku' => 'LEGACY-'.Str::uuid(),
            'stock' => 20,
            'reserved' => $reserved,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Legacy cleanup variant',
            'variant_code' => 'LEGACY-V-'.Str::uuid(),
            'stock' => 20,
            'reserved' => $reserved,
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
            'customer_name' => 'Legacy cleanup customer',
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
