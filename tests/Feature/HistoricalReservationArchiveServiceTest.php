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
use App\Services\HistoricalReservationArchiveService;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalReservationArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_closes_only_historical_ambiguous_rows_and_is_idempotent(): void
    {
        $fixture = $this->inventory();
        $historical = $this->reservation($fixture, 5, old: true);
        $temporary = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $stale = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $stale->forceFill(['last_seen_at' => now()->subMinutes(10), 'expires_at' => now()->subMinute()])->save();

        $activeValid = $this->reservation($fixture, 4, old: true, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $draft = $this->order(PreinvoiceOrder::STATUS_DRAFT, old: false);
        $draft->forceFill(['draft_token' => $activeValid->token])->save();

        $officialOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $official = $this->reservation($fixture, 6, $officialOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $invalidOrder = $this->order(PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, old: false);
        $invalid = $this->reservation($fixture, 7, $invalidOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $legacyOrder = $this->order(PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, old: true);
        $legacy = $this->reservation($fixture, 8, $legacyOrder, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $invoiceOrder = $this->order(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, old: true);
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $invoiceOrder->id,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);
        $invoiceLinked = $this->reservation($fixture, 9, $invoiceOrder, old: true, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $consumed = $this->reservation($fixture, 10, old: false);
        $consumed->forceFill(['converted_at' => now()])->save();
        $released = $this->reservation($fixture, 11, old: false);
        $released->forceFill(['released_at' => now(), 'release_reason' => 'manual_release'])->save();

        $ids = collect([$historical, $temporary, $stale, $activeValid, $official, $invalid, $legacy, $invoiceLinked, $consumed, $released])
            ->pluck('id')->all();
        $result = app(HistoricalReservationArchiveService::class)->archive($ids, now());

        $this->assertSame(1, $result['archived']);
        $this->assertSame(HistoricalReservationArchiveService::RELEASE_REASON, $historical->fresh()->release_reason);
        foreach ([$temporary, $stale, $activeValid, $official, $invalid, $legacy, $invoiceLinked] as $protected) {
            $this->assertNull($protected->fresh()->released_at, 'protected reservation '.$protected->id.' was archived');
        }

        $again = app(HistoricalReservationArchiveService::class)->archive($ids, now());
        $this->assertSame(0, $again['archived']);
        $this->assertTrue(PreinvoiceDraftReservation::query()->whereKey($historical->id)->exists());
    }

    public function test_archive_is_stock_projection_and_movement_neutral_per_variant_and_in_total(): void
    {
        $first = $this->inventory(41);
        $second = $this->inventory(59);
        $one = $this->reservation($first, 5, old: true);
        $two = $this->reservation($second, 7, old: true);
        $variantIds = [$first['variant']->id, $second['variant']->id];
        $centralId = WarehouseStockService::centralWarehouseId();

        $totalBefore = (int) WarehouseStock::query()->where('warehouse_id', $centralId)->sum('quantity');
        $rowsBefore = WarehouseStock::query()->where('warehouse_id', $centralId)
            ->whereIn('product_variant_id', $variantIds)->orderBy('product_variant_id')->pluck('quantity', 'product_variant_id')->all();
        $movementsBefore = DB::table('stock_movements')->count();
        $projectionBefore = app(ReservationQueryService::class)->quantitiesByVariant(variantIds: $variantIds)->all();

        $result = app(HistoricalReservationArchiveService::class)->archive([$one->id, $two->id], now());

        $this->assertSame(2, $result['archived']);
        $this->assertSame($totalBefore, (int) WarehouseStock::query()->where('warehouse_id', $centralId)->sum('quantity'));
        $this->assertSame($rowsBefore, WarehouseStock::query()->where('warehouse_id', $centralId)
            ->whereIn('product_variant_id', $variantIds)->orderBy('product_variant_id')->pluck('quantity', 'product_variant_id')->all());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($projectionBefore, app(ReservationQueryService::class)->quantitiesByVariant(variantIds: $variantIds)->all());
    }

    private function inventory(int $stock = 100): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Archive '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id, 'name' => 'Archive product '.Str::uuid(), 'sku' => 'ARCH-'.Str::uuid(),
            'stock' => $stock, 'reserved' => 0, 'price' => 1000, 'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Archive variant', 'variant_code' => 'ARCH-V-'.Str::uuid(),
            'stock' => $stock, 'reserved' => 0, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true,
        ]));
        $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'quantity' => $stock,
        ]));

        return compact('product', 'variant', 'warehouseStock');
    }

    private function reservation(array $fixture, int $quantity, ?PreinvoiceOrder $order = null, bool $old = true, ?string $scope = null): PreinvoiceDraftReservation
    {
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(), 'preinvoice_order_id' => $order?->id,
            'product_id' => $fixture['product']->id, 'variant_id' => $fixture['variant']->id,
            'quantity' => $quantity, 'reservation_scope' => $scope,
            'last_seen_at' => $old ? now()->subDays(10) : now(),
            'expires_at' => $old ? now()->subDays(10) : now()->addHour(),
        ]);
        if ($old) {
            DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
                'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
            ]);
        }

        return $reservation->refresh();
    }

    private function order(string $status, bool $old): PreinvoiceOrder
    {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(), 'created_by' => $user->id, 'seller_id' => $user->id,
            'document_date' => now(), 'status' => $status, 'customer_name' => 'Archive customer',
            'customer_mobile' => '09120000000', 'total_price' => 1000,
        ]));
        if ($old) {
            DB::table('preinvoice_orders')->where('id', $order->id)->update([
                'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
            ]);
        }

        return $order->refresh();
    }
}
