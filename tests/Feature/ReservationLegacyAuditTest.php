<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationLegacyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_temporary_reservation_is_detected(): void
    {
        $fixture = $this->inventoryFixture();
        $old = $this->reservation($fixture, 5, ageDays: 90, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $this->artisan('inventory:audit-legacy-reservations --output=testing/legacy-audit-temp')
            ->expectsOutputToContain('legacy_candidate_count')
            ->assertSuccessful();

        $rows = $this->csv('testing/legacy-audit-temp/legacy-reservations.csv');
        $row = collect($rows)->firstWhere('id', (string) $old->id);

        $this->assertNotNull($row);
        $this->assertSame('temporary', $row['type']);
        $this->assertSame('legacy_candidate', $row['classification']);
        $this->assertSame('REMOVE_LEGACY', $row['recommended_action']);
        $this->assertGreaterThanOrEqual(80, (int) $row['age_days']);
    }

    public function test_active_preinvoice_is_excluded(): void
    {
        $fixture = $this->inventoryFixture();
        // Recent order still within the review window (< 72h) — a genuinely
        // "active" official preinvoice reservation, not a critical one.
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, ageDays: 1);
        $active = $this->reservation($fixture, 3, $order, ageDays: 1, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $this->artisan('inventory:audit-legacy-reservations --output=testing/legacy-audit-active')
            ->assertSuccessful();

        $rows = $this->csv('testing/legacy-audit-active/legacy-reservations.csv');
        $row = collect($rows)->firstWhere('id', (string) $active->id);

        $this->assertNotNull($row);
        $this->assertSame('official', $row['type']);
        $this->assertSame('official_preinvoice', $row['classification']);
        $this->assertSame('KEEP', $row['recommended_action']);
    }

    public function test_invoice_linked_reservation_is_excluded(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ageDays: 90);
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);
        $consumed = $this->reservation($fixture, 2, $order, ageDays: 90, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $consumed->forceFill(['converted_at' => now()])->save();

        $this->artisan('inventory:audit-legacy-reservations --output=testing/legacy-audit-invoice')
            ->assertSuccessful();

        $rows = $this->csv('testing/legacy-audit-invoice/legacy-reservations.csv');
        $row = collect($rows)->firstWhere('id', (string) $consumed->id);

        $this->assertNotNull($row);
        $this->assertSame((string) $invoice->id, $row['invoice_id']);
        $this->assertSame('KEEP', $row['recommended_action']);
        $this->assertNotSame('REMOVE_LEGACY', $row['recommended_action']);
    }

    public function test_no_stock_mutation_happens(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 5, ageDays: 90, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, ageDays: 90);
        $this->reservation($fixture, 3, $order, ageDays: 90, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;
        $productReserved = $fixture['product']->reserved;
        $variantReserved = $fixture['variant']->reserved;
        $reservationSnapshot = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();

        $this->artisan('inventory:audit-legacy-reservations --output=testing/legacy-audit-nomutate')
            ->assertSuccessful();

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
        $this->assertSame($productReserved, $fixture['product']->fresh()->reserved);
        $this->assertSame($variantReserved, $fixture['variant']->fresh()->reserved);
        $this->assertSame($reservationSnapshot, DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson());
    }

    public function test_report_generation_works(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 4, ageDays: 45, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, ageDays: 90);
        $this->reservation($fixture, 6, $order, ageDays: 90, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $this->artisan('inventory:audit-legacy-reservations --output=testing/legacy-audit-report')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('testing/legacy-audit-report/legacy-reservations.csv');
        Storage::disk('local')->assertExists('testing/legacy-audit-report/summary.json');

        $summary = json_decode(Storage::disk('local')->get('testing/legacy-audit-report/summary.json'), true);

        $this->assertSame(2, $summary['total_reservations_scanned']);
        $this->assertGreaterThanOrEqual(1, $summary['age_40_plus_days_count']);
        $this->assertGreaterThanOrEqual(1, $summary['age_80_plus_days_count']);
        $this->assertSame(1, $summary['official_preinvoice_count']);
        $this->assertFalse($summary['data_changed']);
        $this->assertFalse($summary['stock_changed']);

        $rows = $this->csv('testing/legacy-audit-report/legacy-reservations.csv');
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['id', 'product', 'variant', 'quantity', 'type', 'classification', 'created_at', 'last_activity', 'age_days', 'user', 'customer', 'preinvoice_order_id', 'invoice_id', 'recommended_action'],
            array_keys($rows[0]),
        );
    }

    private function csv(string $path): array
    {
        $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk('local')->get($path))));
        $head = array_shift($lines);

        return array_map(fn (array $line) => array_combine($head, $line), array_filter($lines));
    }

    private function inventoryFixture(): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'LegacyAudit '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Legacy audit product',
            'sku' => 'LAUDIT-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Legacy audit variant',
            'variant_code' => 'LAUDIT-V-'.Str::uuid(),
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
        int $ageDays = 90,
        ?string $scope = null,
    ): PreinvoiceDraftReservation {
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'preinvoice_order_id' => $order?->id,
            'product_id' => $fixture['product']->id,
            'variant_id' => $fixture['variant']->id,
            'quantity' => $quantity,
            'reservation_scope' => $scope,
            'last_seen_at' => now()->subDays($ageDays),
            'expires_at' => now()->subDays($ageDays),
        ]);
        $timestamp = now()->subDays($ageDays);
        DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $reservation->refresh();
    }

    private function order(string $status, int $ageDays): PreinvoiceOrder
    {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'seller_id' => $user->id,
            'document_date' => now(),
            'status' => $status,
            'customer_name' => 'Legacy audit customer',
            'customer_mobile' => '09120000000',
            'total_price' => 1000,
        ]));
        $timestamp = now()->subDays($ageDays);
        DB::table('preinvoice_orders')->where('id', $order->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $order->refresh();
    }
}
