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
use App\Services\ReservationProjectionService;
use App\Services\StockCountDocumentService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_is_read_only_and_rebuild_uses_only_canonical_active_reservations(): void
    {
        [$product, $first, $second, $warehouseRows] = $this->fixture();
        $seller = User::factory()->create();

        $this->reservation($seller, $product, $first, 3, null, PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $holding = $this->order($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE);
        $this->reservation($seller, $product, $first, 4, $holding, PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $consumed = $this->reservation($seller, $product, $first, 11, $this->order($seller, PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE), PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $consumed->forceFill(['converted_at' => now()])->save();
        $released = $this->reservation($seller, $product, $first, 13, null, PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $released->forceFill(['released_at' => now(), 'release_reason' => 'test'])->save();
        $historical = $this->reservation($seller, $product, $first, 17, null, PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $historical->forceFill(['last_seen_at' => now()->subDays(10), 'expires_at' => now()->subDays(10)])->save();
        $invoiceOrder = $this->order($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE);
        $this->reservation($seller, $product, $first, 19, $invoiceOrder, PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(), 'preinvoice_order_id' => $invoiceOrder->id,
            'subtotal' => 1000, 'total' => 1000, 'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);

        $beforeReservations = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();
        $beforeWarehouse = DB::table('warehouse_stocks')->orderBy('id')->get()->toJson();
        $beforeMovements = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $service = app(ReservationProjectionService::class);

        $inspection = $service->inspect([$product->id], now());

        $this->assertSame(7, $inspection['variants'][$first->id]['expected_reserved']);
        $this->assertSame(0, $inspection['variants'][$second->id]['expected_reserved']);
        $this->assertSame(7, $inspection['products'][$product->id]['expected_reserved']);
        $this->assertSame(99, (int) $first->fresh()->reserved);
        $this->assertSame(88, (int) $product->fresh()->reserved);

        $result = $service->rebuild([$product->id], now());

        $this->assertSame(7, (int) $first->fresh()->reserved);
        $this->assertSame(0, (int) $second->fresh()->reserved);
        $this->assertSame(7, (int) $product->fresh()->reserved);
        $this->assertSame(2, $result['summary']['variants_checked']);
        $this->assertSame(2, $result['summary']['variants_changed']);
        $this->assertSame(1, $result['summary']['products_changed']);
        $this->assertSame(0, $result['summary']['reserved_difference_after']);
        $this->assertFalse($result['summary']['warehouse_stock_changed']);
        $this->assertFalse($result['summary']['stock_movement_created']);
        $this->assertSame($beforeReservations, DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeWarehouse, DB::table('warehouse_stocks')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeMovements, DB::table('stock_movements')->orderBy('id')->get()->toJson());

        $again = $service->rebuild([$product->id], now());
        $this->assertSame(0, $again['summary']['variants_changed']);
        $this->assertSame(0, $again['summary']['products_changed']);
    }

    public function test_stock_count_business_read_ignores_corrupted_reserved_projection(): void
    {
        [$product, $variant] = $this->fixture();
        $seller = User::factory()->create();
        $this->reservation($seller, $product, $variant, 6, null, PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $variant->forceFill(['reserved' => 999])->save();

        $this->assertSame(6, app(StockCountDocumentService::class)->activeReserved($variant->fresh()));
        $this->assertSame(999, (int) $variant->fresh()->reserved);
    }

    private function fixture(): array
    {
        $category = Category::query()->create(['name' => 'Projection '.Str::uuid()]);
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id, 'name' => 'Projection product', 'sku' => 'PROJ-'.Str::uuid(),
            'stock' => 30, 'reserved' => 88, 'price' => 1000, 'is_sellable' => true,
        ]));
        $variants = collect(['First', 'Second'])->map(fn (string $name, int $index) => ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => $name, 'variant_code' => 'PROJ-'.Str::uuid(),
            'stock' => 15, 'reserved' => $index === 0 ? 99 : 66, 'sell_price' => 1000,
            'is_active' => true, 'sales_enabled' => true,
        ]));
        $warehouseRows = $variants->map(fn (ProductVariant $variant) => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'quantity' => 15,
        ]));

        return [$product, $variants[0], $variants[1], $warehouseRows];
    }

    private function order(User $seller, string $status): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
            'status' => $status, 'customer_name' => 'Projection customer', 'customer_mobile' => '09120000000',
            'total_price' => 1000,
        ]);
    }

    private function reservation(User $seller, Product $product, ProductVariant $variant, int $quantity, ?PreinvoiceOrder $order, string $scope): PreinvoiceDraftReservation
    {
        return PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(), 'user_id' => $seller->id, 'preinvoice_order_id' => $order?->id,
            'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $quantity,
            'reservation_scope' => $scope, 'last_seen_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }
}
