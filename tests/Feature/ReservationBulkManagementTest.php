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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5 — Bulk Reservation Management.
 *
 * Every scenario here exercises the two bulk HTTP endpoints
 * (bulk-release / bulk-legacy-cleanup / bulk-export) exactly the way the UI
 * does, never the underlying services directly, since the safety rules this
 * phase adds (per-row re-validation, batch limits, distinct IDs, permission
 * separation) live in the controller/request layer.
 */
class ReservationBulkManagementTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Bulk release
    // ---------------------------------------------------------------

    public function test_authorized_user_can_bulk_release_valid_reservations(): void
    {
        $fixture = $this->inventoryFixture(20);
        $first = $this->reservation($fixture, 3, old: true);
        $second = $this->reservation($fixture, 4, old: true);

        $response = $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$first->id, $second->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        $response->assertJson([
            'requested' => 2,
            'released' => 2,
            'skipped' => 0,
            'failed' => 0,
            'quantity_released' => 7,
        ]);
    }

    public function test_bulk_release_uses_normal_release_semantics(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $warehouseQuantityBefore = $fixture['warehouseStock']->quantity;

        $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        // Normal release semantics per InventoryReservationReleaseService: the
        // reserved cache drops and the central warehouse quantity increases
        // by the same amount. (Neither this service nor WarehouseStockService
        // writes a stock_movements row for a manual release today — nothing
        // in this phase changes that existing behavior.)
        $this->assertSame(0, $fixture['product']->fresh()->reserved);
        $this->assertSame(0, $fixture['variant']->fresh()->reserved);
        $this->assertSame($warehouseQuantityBefore + 5, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertNotNull($reservation->fresh()->released_at);
    }

    public function test_invalid_non_releasable_reservation_is_skipped(): void
    {
        $fixture = $this->inventoryFixture(5);
        $fresh = $this->reservation($fixture, 5, old: false);

        $response = $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$fresh->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        $response->assertJson(['requested' => 1, 'released' => 0, 'skipped' => 1, 'failed' => 0]);
        $this->assertNull($fresh->fresh()->released_at);
    }

    public function test_one_invalid_row_does_not_block_other_valid_rows(): void
    {
        $fixture = $this->inventoryFixture(10);
        $valid = $this->reservation($fixture, 3, old: true);
        $invalid = $this->reservation($fixture, 4, old: false);

        $response = $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$valid->id, $invalid->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        $response->assertJson(['requested' => 2, 'released' => 1, 'skipped' => 1, 'failed' => 0]);
        $this->assertNotNull($valid->fresh()->released_at);
        $this->assertNull($invalid->fresh()->released_at);
    }

    public function test_unauthorized_user_cannot_bulk_release(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);

        $this->actingAs($this->viewOnlyUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertForbidden();

        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_concurrent_stale_state_is_safely_revalidated_before_release(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);

        // Simulate another user releasing it between page load and submit.
        $reservation->forceFill([
            'released_at' => now(),
            'release_reason' => 'race_condition_release',
        ])->save();
        $variantReservedBefore = $fixture['variant']->fresh()->reserved;
        $productReservedBefore = $fixture['product']->fresh()->reserved;

        $response = $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        $response->assertJson(['released' => 0, 'skipped' => 1]);
        $this->assertSame($variantReservedBefore, $fixture['variant']->fresh()->reserved);
        $this->assertSame($productReservedBefore, $fixture['product']->fresh()->reserved);
    }

    public function test_repeated_bulk_release_submission_does_not_duplicate_stock_changes(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $user = $this->releaseUser();

        $this->actingAs($user)
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk()
            ->assertJson(['released' => 1]);

        $warehouseQuantityAfterFirst = $fixture['warehouseStock']->fresh()->quantity;
        $movementCountAfterFirst = DB::table('stock_movements')->count();

        $this->actingAs($user)
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk()
            ->assertJson(['released' => 0, 'skipped' => 1]);

        $this->assertSame($warehouseQuantityAfterFirst, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame($movementCountAfterFirst, DB::table('stock_movements')->count());
    }

    // ---------------------------------------------------------------
    // Bulk legacy cleanup
    // ---------------------------------------------------------------

    public function test_legacy_candidate_can_be_bulk_cleaned(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);

        $response = $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id],
            ])
            ->assertOk();

        $response->assertJson(['requested' => 1, 'closed' => 1, 'quantity_closed' => 5, 'warehouse_stock_changed' => false]);
        $this->assertNotNull($legacy->fresh()->released_at);
        $this->assertSame('legacy_cleanup', $legacy->fresh()->release_reason);
    }

    public function test_legacy_cleanup_does_not_change_warehouse_stock(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $warehouseQuantityBefore = $fixture['warehouseStock']->quantity;

        $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id],
            ])
            ->assertOk();

        $this->assertSame($warehouseQuantityBefore, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame(0, $fixture['product']->fresh()->reserved);
        $this->assertSame(0, $fixture['variant']->fresh()->reserved);
    }

    public function test_legacy_cleanup_creates_no_stock_movement(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $movementCountBefore = DB::table('stock_movements')->count();

        $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id],
            ])
            ->assertOk();

        $this->assertSame($movementCountBefore, DB::table('stock_movements')->count());
    }

    public function test_active_temporary_reservation_cannot_be_legacy_cleaned(): void
    {
        $fixture = $this->inventoryFixture(4);
        $active = $this->reservation($fixture, 4, old: false);

        $response = $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$active->id],
            ])
            ->assertOk();

        $response->assertJson(['closed' => 0]);
        $this->assertNull($active->fresh()->released_at);
        $this->assertSame(4, $fixture['variant']->fresh()->reserved);
    }

    public function test_official_preinvoice_cannot_be_legacy_cleaned(): void
    {
        $fixture = $this->inventoryFixture(6);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $official = $this->reservation($fixture, 6, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $response = $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$official->id],
            ])
            ->assertOk();

        $response->assertJson(['closed' => 0]);
        $this->assertNull($official->fresh()->released_at);
        $this->assertSame(6, $fixture['variant']->fresh()->reserved);
    }

    public function test_invoice_linked_reservation_cannot_be_legacy_cleaned(): void
    {
        $fixture = $this->inventoryFixture(4);
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: true);
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);
        $reservation = $this->reservation($fixture, 4, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $response = $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$reservation->id],
            ])
            ->assertOk();

        $response->assertJson(['closed' => 0]);
        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_mixed_legacy_selection_processes_only_eligible_rows(): void
    {
        $fixture = $this->inventoryFixture(15);
        $legacy = $this->reservation($fixture, 5, old: true);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $official = $this->reservation($fixture, 10, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $response = $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id, $official->id],
            ])
            ->assertOk();

        $response->assertJson(['requested' => 2, 'closed' => 1, 'quantity_closed' => 5]);
        $this->assertNotNull($legacy->fresh()->released_at);
        $this->assertNull($official->fresh()->released_at);
    }

    public function test_user_without_legacy_cleanup_permission_cannot_bulk_cleanup(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);

        $this->actingAs($this->viewOnlyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id],
            ])
            ->assertForbidden();

        $this->assertNull($legacy->fresh()->released_at);
    }

    // ---------------------------------------------------------------
    // Validation / batch limits
    // ---------------------------------------------------------------

    public function test_maximum_batch_limit_is_enforced(): void
    {
        $ids = range(1, 101);

        $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => $ids,
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);

        $this->actingAs($this->releaseUser())
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id, $reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertStatus(422);

        $this->assertNull($reservation->fresh()->released_at);
    }

    // ---------------------------------------------------------------
    // Bulk export
    // ---------------------------------------------------------------

    public function test_bulk_export_contains_only_selected_ids(): void
    {
        $fixture = $this->inventoryFixture(15);
        $included = $this->reservation($fixture, 3, old: true);
        $alsoIncluded = $this->reservation($fixture, 4, old: true);
        $excluded = $this->reservation($fixture, 5, old: true);

        $response = $this->actingAs($this->viewOnlyUser())
            ->post(route('warehouse-reservations.bulk-export'), [
                'reservation_ids' => [$included->id, $alsoIncluded->id],
            ])
            ->assertOk();

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $response->streamedContent())))));
        $exportedIds = array_map(fn (array $row): string => $row[0], array_slice($rows, 1));

        $this->assertSame([(string) $included->id, (string) $alsoIncluded->id], $exportedIds);
        $this->assertNotContains((string) $excluded->id, $exportedIds);
    }

    public function test_bulk_export_is_read_only(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $before = $this->snapshot($fixture);

        $this->actingAs($this->viewOnlyUser())
            ->post(route('warehouse-reservations.bulk-export'), [
                'reservation_ids' => [$reservation->id],
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertSame($before, $this->snapshot($fixture));
    }

    // ---------------------------------------------------------------
    // Isolation from unrelated invoice/preinvoice data
    // ---------------------------------------------------------------

    public function test_no_unrelated_invoice_or_preinvoice_data_changes_during_bulk_actions(): void
    {
        $fixture = $this->inventoryFixture(20);
        $legacy = $this->reservation($fixture, 5, old: true);

        $unrelatedOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false, customerName: 'Untouched customer');
        $unrelatedReservation = $this->reservation($fixture, 6, $unrelatedOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $unrelatedOrderSnapshot = $unrelatedOrder->toArray();

        $this->actingAs($this->legacyUser())
            ->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
                'reservation_ids' => [$legacy->id],
            ])
            ->assertOk();

        $this->assertNull($unrelatedReservation->fresh()->released_at);
        $this->assertSame($unrelatedOrderSnapshot['status'], $unrelatedOrder->fresh()->status);
        $this->assertSame($unrelatedOrderSnapshot['customer_name'], $unrelatedOrder->fresh()->customer_name);
    }

    // ---------------------------------------------------------------
    // Fixtures / helpers
    // ---------------------------------------------------------------

    private function releaseUser(): User
    {
        return $this->userWithPermissions(['warehouse_reservations.view', 'warehouse_reservations.release']);
    }

    private function legacyUser(): User
    {
        return $this->userWithPermissions(['warehouse_reservations.view', 'inventory.reservation.legacy_cleanup']);
    }

    private function viewOnlyUser(): User
    {
        return $this->userWithPermissions(['warehouse_reservations.view']);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::findOrCreate('reservation-bulk-'.Str::random(8), 'web');

        foreach ($permissions as $key) {
            $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function inventoryFixture(int $reserved): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Bulk mgmt '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Bulk mgmt product',
            'sku' => 'BULK-'.Str::uuid(),
            'stock' => 100,
            'reserved' => $reserved,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Bulk mgmt variant',
            'variant_code' => 'BULK-V-'.Str::uuid(),
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

    private function order(string $status, bool $old, ?string $customerName = null): PreinvoiceOrder
    {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'seller_id' => $user->id,
            'document_date' => now(),
            'status' => $status,
            'customer_name' => $customerName ?? 'Bulk mgmt customer',
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
