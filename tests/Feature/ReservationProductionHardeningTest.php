<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\InventoryReservationReleaseService;
use App\Services\LegacyReservationCleanupService;
use App\Services\ReservationClassificationService;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final Phase — production readiness hardening for Warehouse Reservation
 * Management. These tests lock in the guarantees the final report relies on:
 * one authoritative reservation-truth source shared by every consumer, the
 * two mutation write-paths staying strictly separate, read-only surfaces
 * staying read-only, and the permission-catalog prefix-collision class of
 * bug (found and fixed for inventory.reservation.legacy_cleanup) never
 * silently regressing for this or any future action permission.
 */
class ReservationProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // 1-3: one authoritative reservation truth everywhere
    // ---------------------------------------------------------------

    public function test_dashboard_legacy_candidate_count_matches_authoritative_scope(): void
    {
        $fixture = $this->inventoryFixture(5);
        $this->reservation($fixture, 5, old: true);
        $queries = app(ReservationQueryService::class);

        $stats = $queries->dashboardStatistics(now());
        $rawCount = PreinvoiceDraftReservation::query()
            ->legacyCleanupCandidates(PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now())
            ->count();

        $this->assertSame($rawCount, $stats['legacy_candidates']['count']);
        $this->assertGreaterThan(0, $rawCount);
    }

    public function test_product_warehouse_stock_endpoint_matches_reservation_query_service(): void
    {
        $fixture = $this->inventoryFixture(0);
        // A stale/abandoned temporary reservation is correctly excluded from
        // the active reserved-cache definition (that is what legacy cleanup
        // exists for) — use a fresh, still-active one here instead.
        $reservation = $this->reservation($fixture, 6, old: false);
        $queries = app(ReservationQueryService::class);

        $authoritative = (int) $queries
            ->quantitiesByVariant(variantIds: [(int) $fixture['variant']->id])
            ->get((int) $fixture['variant']->id, 0);

        $response = $this->actingAs($this->userWithPermissions(['page.products']))
            ->getJson(route('products.warehouse-stock', $fixture['product']))
            ->assertOk();

        $this->assertSame($authoritative, $response->json('summary.reserved_quantity'));
        $this->assertGreaterThan(0, $authoritative);
    }

    public function test_warehouse_breakdown_reserved_quantity_uses_same_truth_as_query_service(): void
    {
        $fixture = $this->inventoryFixture(0);
        $this->reservation($fixture, 4, old: false);
        $queries = app(ReservationQueryService::class);

        $authoritative = (int) $queries
            ->quantitiesByVariant(variantIds: [(int) $fixture['variant']->id])
            ->get((int) $fixture['variant']->id, 0);

        $response = $this->actingAs($this->userWithPermissions(['page.products']))
            ->getJson(route('products.warehouse-stock', $fixture['product']))
            ->assertOk();

        $rows = collect($response->json('rows'));
        $centralRow = $rows->firstWhere('warehouse_id', WarehouseStockService::centralWarehouseId());

        $this->assertNotNull($centralRow);
        $this->assertSame($authoritative, $centralRow['reserved_quantity']);
        $this->assertGreaterThan(0, $authoritative);
    }

    public function test_released_reservation_is_excluded_from_active_reserved_quantity(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $queries = app(ReservationQueryService::class);

        app(InventoryReservationReleaseService::class)->releaseDraftReservation(
            $reservation,
            User::factory()->create(),
            'انصراف مشتری',
        );

        $active = (int) $queries->quantitiesByVariant(variantIds: [(int) $fixture['variant']->id])->get((int) $fixture['variant']->id, 0);
        $this->assertSame(0, $active);
    }

    public function test_legacy_cleaned_reservation_is_excluded_from_reserved_cache(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);

        app(LegacyReservationCleanupService::class)->cleanup(
            [$legacy->id],
            PreinvoiceDraftReservation::LEGACY_STALE_HOURS,
            now(),
        );

        $this->assertSame(0, $fixture['product']->fresh()->reserved);
        $this->assertSame(0, $fixture['variant']->fresh()->reserved);
    }

    // ---------------------------------------------------------------
    // 6-11: golden business rules stay separate and guarded
    // ---------------------------------------------------------------

    public function test_legacy_cleanup_does_not_change_physical_warehouse_stock(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $warehouseBefore = $fixture['warehouseStock']->quantity;

        app(LegacyReservationCleanupService::class)->cleanup([$legacy->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame($warehouseBefore, $fixture['warehouseStock']->fresh()->quantity);
    }

    public function test_normal_release_returns_stock_exactly_once(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $warehouseBefore = $fixture['warehouseStock']->quantity;

        app(InventoryReservationReleaseService::class)->releaseDraftReservation(
            $reservation,
            User::factory()->create(),
            'انصراف مشتری',
        );

        $this->assertSame($warehouseBefore + 5, $fixture['warehouseStock']->fresh()->quantity);
    }

    public function test_repeating_normal_release_does_not_return_stock_twice(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $service = app(InventoryReservationReleaseService::class);
        $user = User::factory()->create();

        $service->releaseDraftReservation($reservation, $user, 'انصراف مشتری');
        $warehouseAfterFirst = $fixture['warehouseStock']->fresh()->quantity;

        $this->expectException(ValidationException::class);

        try {
            $service->releaseDraftReservation($reservation->fresh(), $user, 'انصراف مشتری');
        } finally {
            $this->assertSame($warehouseAfterFirst, $fixture['warehouseStock']->fresh()->quantity);
        }
    }

    public function test_invoice_linked_reservation_is_never_legacy_cleaned(): void
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

        $result = app(LegacyReservationCleanupService::class)->cleanup([$reservation->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame(0, $result['closed']);
        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_active_official_preinvoice_is_never_legacy_cleaned(): void
    {
        $fixture = $this->inventoryFixture(6);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $reservation = $this->reservation($fixture, 6, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $result = app(LegacyReservationCleanupService::class)->cleanup([$reservation->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame(0, $result['closed']);
        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_active_temporary_reservation_is_never_legacy_cleaned(): void
    {
        $fixture = $this->inventoryFixture(4);
        $active = $this->reservation($fixture, 4, old: false);

        $result = app(LegacyReservationCleanupService::class)->cleanup([$active->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame(0, $result['closed']);
        $this->assertNull($active->fresh()->released_at);
    }

    // ---------------------------------------------------------------
    // 12: permission collision regression
    // ---------------------------------------------------------------

    public function test_legacy_cleanup_permission_does_not_collide_with_inventory_prefix_page(): void
    {
        // Locks in the fix: this action permission must never be silently
        // rerouted through a page-permission check it was never meant to
        // share, just because it happens to start with "inventory.".
        $this->assertNull(PageAccessCatalog::pagePermissionForLegacy('inventory.reservation.legacy_cleanup'));

        $role = Role::findOrCreate('legacy-cleanup-collision-'.Str::random(8), 'web');
        $role->givePermissionTo(Permission::query()->where('key', 'inventory.reservation.legacy_cleanup')->firstOrFail());
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue(PermissionCatalog::userHasPermission($user, 'inventory.reservation.legacy_cleanup'));
        // Granting this permission must not implicitly grant the unrelated
        // warehouse.stocks page permission it collided with before the fix.
        $this->assertFalse(PageAccessCatalog::userCan($user, 'page.warehouse.stocks'));
    }

    public function test_reservation_action_permissions_never_resolve_through_page_permission_redirection(): void
    {
        // The three permissions warehouse-reservations routes check directly
        // (route.permission:<key>) must resolve via a direct Spatie
        // permission check every time — never get silently redirected to an
        // unrelated page permission the user was never granted, which is
        // exactly the class of bug found and fixed for
        // inventory.reservation.legacy_cleanup (it collided with the
        // "inventory." legacy-prefix sweep owned by the warehouse.stocks page).
        foreach (['warehouse_reservations.view', 'warehouse_reservations.release', 'inventory.reservation.legacy_cleanup'] as $key) {
            $this->assertNull(
                PageAccessCatalog::pagePermissionForLegacy($key),
                "Permission key [{$key}] is being redirected to a page permission instead of checked directly."
            );
        }
    }

    // ---------------------------------------------------------------
    // 13-14: bulk safety
    // ---------------------------------------------------------------

    public function test_unauthorized_bulk_release_is_forbidden(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);

        $this->actingAs($this->userWithPermissions(['warehouse_reservations.view']))
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertForbidden();

        $this->assertNull($reservation->fresh()->released_at);
    }

    public function test_bulk_release_revalidates_stale_state_before_mutating(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $reservation->forceFill(['released_at' => now(), 'release_reason' => 'already_released_elsewhere'])->save();

        $response = $this->actingAs($this->userWithPermissions(['warehouse_reservations.view', 'warehouse_reservations.release']))
            ->postJson(route('warehouse-reservations.bulk-release'), [
                'reservation_ids' => [$reservation->id],
                'release_reason' => 'انصراف مشتری',
            ])
            ->assertOk();

        $response->assertJson(['released' => 0, 'skipped' => 1]);
    }

    // ---------------------------------------------------------------
    // 15-17: read-only surfaces stay read-only
    // ---------------------------------------------------------------

    public function test_detail_page_remains_read_only(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $before = $this->snapshot($fixture);

        $this->actingAs($this->userWithPermissions(['warehouse_reservations.view']))
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk();

        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_bulk_csv_export_remains_read_only(): void
    {
        $fixture = $this->inventoryFixture(5);
        $reservation = $this->reservation($fixture, 5, old: true);
        $before = $this->snapshot($fixture);

        $this->actingAs($this->userWithPermissions(['warehouse_reservations.view']))
            ->post(route('warehouse-reservations.bulk-export'), ['reservation_ids' => [$reservation->id]])
            ->assertOk()
            ->streamedContent();

        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_stock_reservation_integrity_audit_command_remains_read_only(): void
    {
        $fixture = $this->inventoryFixture(5);
        $this->reservation($fixture, 5, old: true);
        $before = $this->snapshot($fixture);

        $this->artisan('inventory:audit-stock-reservation-integrity --summary --output=testing/hardening-audit')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot($fixture));
    }

    // ---------------------------------------------------------------
    // 18-19: cache/stock isolation
    // ---------------------------------------------------------------

    public function test_legacy_cleanup_creates_no_stock_movement_row(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $movementsBefore = DB::table('stock_movements')->count();

        app(LegacyReservationCleanupService::class)->cleanup([$legacy->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    public function test_reserved_cache_rebuild_does_not_modify_warehouse_stock(): void
    {
        $fixture = $this->inventoryFixture(5);
        $this->reservation($fixture, 5, old: true);
        $warehouseBefore = $fixture['warehouseStock']->quantity;

        app(ReservationQueryService::class)->rebuildForProducts([(int) $fixture['product']->id], now());

        $this->assertSame($warehouseBefore, $fixture['warehouseStock']->fresh()->quantity);
    }

    // ---------------------------------------------------------------
    // 20: unrelated invoice/preinvoice data untouched
    // ---------------------------------------------------------------

    public function test_management_actions_never_change_unrelated_preinvoice_order_data(): void
    {
        $fixture = $this->inventoryFixture(20);
        $legacy = $this->reservation($fixture, 5, old: true);

        $unrelatedOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false, customerName: 'Untouched hardening customer');
        $this->reservation($fixture, 6, $unrelatedOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $unrelatedSnapshot = $unrelatedOrder->toArray();

        app(LegacyReservationCleanupService::class)->cleanup([$legacy->id], PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now());

        $this->assertSame($unrelatedSnapshot['status'], $unrelatedOrder->fresh()->status);
        $this->assertSame($unrelatedSnapshot['customer_name'], $unrelatedOrder->fresh()->customer_name);
        $this->assertSame($unrelatedSnapshot['total_price'], $unrelatedOrder->fresh()->total_price);
    }

    // ---------------------------------------------------------------
    // Final-phase regressions: three defects found during the closing audit
    // ---------------------------------------------------------------

    /**
     * scopeCriticalPreinvoice() only checks "official preinvoice without an
     * invoice, older than the critical threshold" — it does not exclude
     * already-released rows, because it is also used as an OR-branch under a
     * caller that has already applied the visibility gate. Used bare on the
     * dashboard it counted historically released/legacy-cleaned reservations
     * as currently critical.
     */
    public function test_dashboard_critical_count_excludes_already_released_reservations(): void
    {
        $fixture = $this->inventoryFixture(0);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $released = $this->reservation($fixture, 7, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $released->forceFill([
            'released_at' => now()->subDay(),
            'release_reason' => LegacyReservationCleanupService::RELEASE_REASON,
        ])->save();

        $stats = app(ReservationQueryService::class)->dashboardStatistics(now());

        $this->assertSame(0, $stats['critical']['count']);
        $this->assertSame(0, $stats['critical']['quantity']);
    }

    public function test_dashboard_critical_count_still_reports_a_genuinely_critical_reservation(): void
    {
        $fixture = $this->inventoryFixture(7);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $this->reservation($fixture, 7, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $stats = app(ReservationQueryService::class)->dashboardStatistics(now());

        $this->assertSame(1, $stats['critical']['count']);
        $this->assertSame(7, $stats['critical']['quantity']);
        // A critical row is by definition a still-held official reservation,
        // so it can never outnumber the official card it is a subset of.
        $this->assertLessThanOrEqual($stats['official']['count'], $stats['critical']['count']);
    }

    /**
     * LegacyReservationCleanupService returns rows keyed by `reservation_id`.
     * The bulk endpoint reconciled them against the requested IDs with
     * pluck('id'), which yielded nulls, so every processed reservation was
     * *also* reported back to the operator as "not found", and the `skipped`
     * counter shown in the result modal was inflated accordingly.
     */
    public function test_bulk_legacy_cleanup_result_does_not_double_report_processed_rows(): void
    {
        $fixture = $this->inventoryFixture(9);
        $legacy = $this->reservation($fixture, 5, old: true);
        $active = $this->reservation($fixture, 4, old: false);

        $response = $this->actingAs($this->userWithPermissions([
            'warehouse_reservations.view',
            'inventory.reservation.legacy_cleanup',
        ]))->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
            'reservation_ids' => [$legacy->id, $active->id],
        ])->assertOk();

        $response->assertJson(['requested' => 2, 'closed' => 1, 'skipped' => 1, 'failed' => 0]);
        $this->assertCount(2, $response->json('items'));
        $this->assertSame(
            [$legacy->id, $active->id],
            collect($response->json('items'))->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_bulk_legacy_cleanup_still_reports_a_genuinely_missing_id(): void
    {
        $fixture = $this->inventoryFixture(5);
        $legacy = $this->reservation($fixture, 5, old: true);
        $missingId = $legacy->id + 5000;

        $response = $this->actingAs($this->userWithPermissions([
            'warehouse_reservations.view',
            'inventory.reservation.legacy_cleanup',
        ]))->postJson(route('warehouse-reservations.bulk-legacy-cleanup'), [
            'reservation_ids' => [$legacy->id, $missingId],
        ])->assertOk();

        $response->assertJson(['requested' => 2, 'closed' => 1, 'skipped' => 1]);
        $this->assertCount(2, $response->json('items'));
        $this->assertSame(
            'skipped',
            collect($response->json('items'))->firstWhere('id', $missingId)['status'],
        );
    }

    /**
     * --summary was accepted by the command signature but had no effect: it
     * still wrote the full CSV/JSON report set to storage. It is the command
     * the production runbook uses for a quick read-only check, so it must
     * print the counters and leave nothing behind.
     */
    public function test_audit_command_summary_mode_prints_counters_without_writing_report_files(): void
    {
        $fixture = $this->inventoryFixture(5);
        $this->reservation($fixture, 5, old: true);
        $directory = 'testing/summary-mode-'.Str::random(8);

        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($directory);

        $this->artisan('inventory:audit-stock-reservation-integrity --summary --output='.$directory)
            ->expectsOutputToContain('total_anomalies')
            ->assertSuccessful();

        $this->assertFalse(
            \Illuminate\Support\Facades\Storage::disk('local')->exists($directory.'/summary.json'),
            '--summary must not write report files.',
        );
    }

    public function test_audit_command_summary_reports_the_authoritative_legacy_candidate_count(): void
    {
        $fixture = $this->inventoryFixture(5);
        $this->reservation($fixture, 5, old: true);

        $expected = PreinvoiceDraftReservation::query()
            ->legacyCleanupCandidates(PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now())
            ->count();

        $this->assertSame(1, $expected);
        $this->artisan('inventory:audit-stock-reservation-integrity --summary')
            ->expectsOutputToContain('"legacy_candidate_count": 1')
            ->assertSuccessful();
    }

    /**
     * Step 5 timeline semantics: a preinvoice order's own created_at must
     * never be presented as the moment this reservation was connected to it.
     */
    public function test_reservation_table_does_not_label_preinvoice_creation_as_reservation_connection(): void
    {
        $fixture = $this->inventoryFixture(6);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: true);
        $this->reservation($fixture, 6, $order, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $response = $this->actingAs($this->userWithPermissions(['warehouse_reservations.view']))
            ->get(route('warehouse-reservations.index'))
            ->assertOk();

        $response->assertSee('ایجاد پیش‌فاکتور مرتبط', false);
        $response->assertDontSee('اتصال رزرو:', false);
    }

    // ---------------------------------------------------------------
    // Fixtures / helpers
    // ---------------------------------------------------------------

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::findOrCreate('reservation-hardening-'.Str::random(8), 'web');

        foreach ($permissions as $key) {
            $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function inventoryFixture(int $reserved): array
    {
        $category = \App\Models\Category::withoutEvents(fn () => \App\Models\Category::query()->create(['name' => 'Hardening '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Hardening product',
            'sku' => 'HARDEN-'.Str::uuid(),
            'stock' => 100,
            'reserved' => $reserved,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Hardening variant',
            'variant_code' => 'HARDEN-V-'.Str::uuid(),
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
            'customer_name' => $customerName ?? 'Hardening customer',
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
            'orders' => DB::table('preinvoice_orders')->orderBy('id')->get()->toJson(),
        ];
    }
}
