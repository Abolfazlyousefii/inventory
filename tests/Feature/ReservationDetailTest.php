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

class ReservationDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_warehouse_manager_can_view_reservation_detail(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk()
            ->assertSee((string) $reservation->id)
            ->assertSee($reservation->token);
    }

    public function test_unauthorized_user_receives_forbidden_response(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->actingAs(User::factory()->create())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertForbidden();
    }

    public function test_product_and_variant_information_displays_correctly(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 7, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk()
            ->assertSee($fixture['product']->name)
            ->assertSee($fixture['variant']->variant_name)
            ->assertSee($fixture['variant']->variant_code)
            ->assertSee('7');
    }

    public function test_classification_displays_correctly(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $reservation = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $response = $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk();

        $response->assertSee('پیش‌فاکتور رسمی');
        $response->assertSee('فعال');
    }

    public function test_temporary_reservation_without_customer_renders_safely(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk()
            ->assertSee('بدون مشتری مرتبط');
    }

    public function test_official_preinvoice_information_displays_correctly(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false, customerName: 'Detail Customer');
        $reservation = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk()
            ->assertSee($order->uuid)
            ->assertSee('Detail Customer')
            ->assertSee(PreinvoiceOrder::STATUS_PENDING_FINANCE);
    }

    public function test_invoice_linked_reservation_can_still_be_viewed(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: false);
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);
        $reservation = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $reservation->forceFill(['converted_at' => now()])->save();

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk()
            ->assertSee((string) $invoice->id)
            ->assertSee($invoice->uuid);
    }

    public function test_timeline_only_shows_events_backed_by_real_timestamps(): void
    {
        $fixture = $this->inventoryFixture();
        // No last_seen_at, no order, no released_at: only "created" should appear.
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'product_id' => $fixture['product']->id,
            'variant_id' => $fixture['variant']->id,
            'quantity' => 1,
            'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
        ]);

        $response = $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk();

        // "آخرین heartbeat" is a static field label shown regardless (as
        // "ثبت نشده" when absent) — only the timeline event text
        // ("دریافت آخرین heartbeat") is conditional on real data existing.
        $response->assertSee('ایجاد رزرو');
        $response->assertDontSee('دریافت آخرین heartbeat');
        $response->assertDontSee('آزادسازی رزرو');
        $response->assertDontSee('صدور فاکتور');
    }

    public function test_activity_log_query_respects_subject_type_subject_id_and_action(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        // Matching subject_type/subject_id, whitelisted action: must show.
        ActivityLog::query()->create([
            'user_id' => null,
            'action' => 'legacy_reservation_cleanup',
            'subject_type' => PreinvoiceDraftReservation::class,
            'subject_id' => $reservation->id,
            'description' => 'Matching legacy cleanup log',
            'properties' => [],
            'occurred_at' => now(),
        ]);

        // Same subject_id but a DIFFERENT subject_type (e.g. a Product with the
        // same numeric ID) must never leak into this reservation's history.
        ActivityLog::query()->create([
            'user_id' => null,
            'action' => 'legacy_reservation_cleanup',
            'subject_type' => Product::class,
            'subject_id' => $reservation->id,
            'description' => 'Unrelated product log that must not appear',
            'properties' => [],
            'occurred_at' => now(),
        ]);

        // Matching subject, but an action outside the known whitelist: must
        // not appear (no fabricated/unsupported action types are surfaced).
        ActivityLog::query()->create([
            'user_id' => null,
            'action' => 'some_unrelated_action',
            'subject_type' => PreinvoiceDraftReservation::class,
            'subject_id' => $reservation->id,
            'description' => 'Unrelated action log that must not appear',
            'properties' => [],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk();

        $response->assertSee('Matching legacy cleanup log');
        $response->assertDontSee('Unrelated product log that must not appear');
        $response->assertDontSee('Unrelated action log that must not appear');
    }

    public function test_opening_detail_page_causes_no_mutation(): void
    {
        $fixture = $this->inventoryFixture();
        $reservation = $this->reservation($fixture, 5, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;
        $productReserved = $fixture['product']->fresh()->reserved;
        $variantReserved = $fixture['variant']->fresh()->reserved;
        $reservationSnapshot = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();

        $this->actingAs($this->reservationManager())
            ->get(route('warehouse-reservations.show', $reservation))
            ->assertOk();

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame($productReserved, $fixture['product']->fresh()->reserved);
        $this->assertSame($variantReserved, $fixture['variant']->fresh()->reserved);
        $this->assertSame($reservationSnapshot, DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    private function reservationManager(array $permissions = ['warehouse_reservations.view']): User
    {
        $role = Role::findOrCreate('reservation-detail-'.Str::random(8), 'web');

        foreach ($permissions as $key) {
            $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function inventoryFixture(): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Detail '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Detail product',
            'sku' => 'DETAIL-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Detail variant',
            'variant_code' => 'DETAIL-V-'.Str::uuid(),
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

    private function order(string $status, bool $old, ?string $customerName = null): PreinvoiceOrder
    {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'seller_id' => $user->id,
            'document_date' => now(),
            'status' => $status,
            'customer_name' => $customerName ?? 'Detail customer',
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
