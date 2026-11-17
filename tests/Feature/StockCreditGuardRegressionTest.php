<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WarehouseShippingController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ShippingMethod;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseTransfer;
use App\Services\SalesReturnCorrectionService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Run ONLY in the repository's SQLite :memory: test configuration.
 * Covers the previously unguarded invoice-linked stock-credit entry points.
 */
class StockCreditGuardRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
	 $this->actingAs(\App\Models\User::factory()->create([
        'is_active' => true,
        'can_access_erp' => true,
	]));
        Http::fake();
        config()->set('services.request_driven_site_stock', false);
        // Some recovered legacy test baselines predate the production column.
        // Only changes the disposable in-memory PHPUnit database.
        if (! Schema::hasColumn('invoices', 'stock_deduction_status')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->string('stock_deduction_status', 32)->nullable();
            });
        }
    }

    private function fixture(?string $status = null, ?int $siteOrderId = 920001): array
    {
        $centralId = WarehouseStockService::centralWarehouseId();
        $central = Warehouse::query()->find($centralId);
        if (! $central) {
            $central = Warehouse::query()->create([
                'id' => $centralId, 'name' => 'Central', 'type' => 'central', 'is_active' => true,
            ]);
        }
        $customer = Customer::query()->create(['name' => 'Stock test customer', 'mobile' => '099900920001', 'opening_balance' => 0]);
        $category = Category::query()->create(['name' => 'Stock guard category']);
        $product = Product::query()->create([
            'name' => 'Stock guard product', 'sku' => 'STOCK-GUARD-PRODUCT-920001', 'category_id' => $category->id,
            'stock' => 10, 'price' => 1000,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Stock guard variant',
            'variant_code' => 'SG-001', 'stock' => 10, 'sell_price' => 1000,
            'is_active' => true,
        ]);
        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $central->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id],
            ['quantity' => 10]
        );
        $invoice = new Invoice();
        $invoice->forceFill([
            'uuid' => 'STOCK-GUARD-920001', 'external_order_id' => $siteOrderId,
            'stock_deduction_status' => $status, 'customer_id' => $customer->id,
            'status' => 'collecting', 'subtotal' => 5000, 'total' => 5000,
        ])->save();
        $item = $invoice->items()->create([
            'product_id' => $product->id, 'variant_id' => $variant->id,
            'quantity' => 5, 'price' => 1000, 'line_total' => 5000,
        ]);

        return [$invoice, $item, $product, $variant, $central, $customer];
    }

    private function stock(Warehouse $central, ProductVariant $variant): int
    {
        return (int) WarehouseStock::query()
            ->where('warehouse_id', $central->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
    }

    private function assertRejectedWithoutChangingStock(callable $action, Warehouse $central, ProductVariant $variant): void
    {
        $before = $this->stock($central, $variant);
        try {
            $action();
            $this->fail('An unverified website invoice was permitted to credit stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }
        $this->assertSame($before, $this->stock($central, $variant));
    }

    public function test_sale_delivery_quantity_reduction_cannot_credit_unverified_site_invoice(): void
    {
        [$invoice, $item, , $variant, $central] = $this->fixture('not_deducted');
        $request = Request::create('/vouchers/sale-delivery/'.$invoice->uuid, 'PUT', [
            'status' => 'collecting',
            'items' => [['id' => $item->id, 'quantity' => 3, 'price' => 1000]],
        ]);
        $this->assertRejectedWithoutChangingStock(
            fn () => app(VoucherController::class)->saleDeliveryUpdate($invoice->uuid, $request),
            $central, $variant
        );
        $this->assertSame(5, (int) $item->fresh()->quantity);
    }

    public function test_sales_voucher_quantity_reduction_cannot_credit_unverified_site_invoice(): void
    {
        [$invoice, $item, , $variant, $central] = $this->fixture('not_deducted');
        $request = Request::create('/vouchers/sales/'.$invoice->uuid, 'PUT', [
            'items' => [['id' => $item->id, 'quantity' => 3, 'price' => 1000]],
        ]);
        $this->assertRejectedWithoutChangingStock(
            fn () => app(VoucherController::class)->salesUpdate($invoice->uuid, $request),
            $central, $variant
        );
        $this->assertSame(5, (int) $item->fresh()->quantity);
        $this->assertSame(0, StockMovement::query()->where('reason', 'sale_edit')->count());
    }

    public function test_verified_sales_voucher_reduction_records_variant_movement(): void
    {
        [$invoice, $item, , $variant, $central] = $this->fixture('deducted');
        $request = Request::create('/vouchers/sales/'.$invoice->uuid, 'PUT', [
            'items' => [['id' => $item->id, 'quantity' => 3, 'price' => 1000]],
        ]);
        app(VoucherController::class)->salesUpdate($invoice->uuid, $request);
        $this->assertSame(12, $this->stock($central, $variant));
        $this->assertSame(3, (int) $item->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $central->id,
            'reason' => 'sale_edit',
            'type' => 'in',
            'quantity' => 2,
            'stock_before' => 10,
            'stock_after' => 12,
        ]);
    }

    public function test_verified_sale_delivery_reduction_uses_actual_delta_and_movement(): void
    {
        [$invoice, $item, , $variant, $central] = $this->fixture('deducted');
        $request = Request::create('/vouchers/sale-delivery/'.$invoice->uuid, 'PUT', [
            'status' => 'collecting',
            'items' => [['id' => $item->id, 'quantity' => 3, 'price' => 1000]],
        ]);
        app(VoucherController::class)->saleDeliveryUpdate($invoice->uuid, $request);
        $this->assertSame(12, $this->stock($central, $variant));
        $this->assertSame(3, (int) $item->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $central->id,
            'reason' => 'sale_edit',
            'quantity' => 2,
            'stock_before' => 10,
            'stock_after' => 12,
        ]);
    }

    public function test_undeducted_site_invoice_cannot_be_shipped(): void
    {
        [$invoice, , , $variant, $central] = $this->fixture('not_deducted');
        $invoice->forceFill(['status' => Invoice::STATUS_READY_TO_SHIP])->save();
        $method = ShippingMethod::query()->firstOrCreate(['name' => 'Stock guard shipping'], ['price' => 0]);
        $request = Request::create('/warehouse/shipping/'.$invoice->uuid, 'POST', [
            'shipping_method_id' => $method->id,
            'shipping_cost' => 0,
        ]);
        $this->assertRejectedWithoutChangingStock(
            fn () => app(WarehouseShippingController::class)->ship($request, $invoice->uuid),
            $central, $variant
        );
        $this->assertSame(Invoice::STATUS_READY_TO_SHIP, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->shipped_at);
    }

    public function test_sale_delivery_cannot_bypass_shipping_guard(): void
    {
        [$invoice, $item, , $variant, $central] = $this->fixture('not_deducted');
        $request = Request::create('/vouchers/sale-delivery/'.$invoice->uuid, 'PUT', [
            'status' => 'shipped',
            'items' => [['id' => $item->id, 'quantity' => 5, 'price' => 1000]],
        ]);
        $this->assertRejectedWithoutChangingStock(
            fn () => app(VoucherController::class)->saleDeliveryUpdate($invoice->uuid, $request),
            $central, $variant
        );
        $this->assertSame('collecting', $invoice->fresh()->status);
    }

    public function test_legacy_customer_return_voucher_cannot_credit_unverified_site_invoice(): void
    {
        [$invoice, $item, $product, $variant, $central] = $this->fixture(null);
        $data = [
            'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'related_invoice_uuid' => $invoice->uuid,
            'to_warehouse_id' => $central->id,
            'from_warehouse_id' => $central->id,
            'items' => [[
                'invoice_item_id' => $item->id, 'product_id' => $product->id,
                'variant_id' => $variant->id, 'quantity' => 1,
            ]],
        ];
        $method = new ReflectionMethod(VoucherController::class, 'createTransfer');
        $this->assertRejectedWithoutChangingStock(
            fn () => $method->invoke(app(VoucherController::class), $data, now()),
            $central, $variant
        );
        $this->assertSame(0, WarehouseTransfer::query()->count());
    }

    public function test_positive_return_correction_cannot_credit_unverified_site_invoice(): void
    {
        [$invoice, , $product, $variant, $central, $customer] = $this->fixture('not_deducted');
        $document = SalesReturnDocument::query()->create([
            'document_number' => 'SG-CORR-001',
            'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
            'status' => SalesReturnDocument::STATUS_APPLIED,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'items_count' => 0, 'total_quantity' => 0, 'total_refund_amount' => 0,
            'return_reason' => 'other',
        ]);
        $key = sprintf('%012d:%012d', $central->id, $variant->id);
        $groups = [[], [$key => [
            'warehouse_id' => $central->id, 'product_id' => $product->id,
            'variant_id' => $variant->id, 'quantity' => 1,
        ]]];
        $proposal = [
            'items' => [], 'total_quantity' => 0, 'total_refund_amount' => 0,
            'document' => ['description' => null, 'reference_number' => null],
        ];
        $method = new ReflectionMethod(SalesReturnCorrectionService::class, 'applyDelta');
        $this->assertRejectedWithoutChangingStock(
            fn () => $method->invoke(
                app(SalesReturnCorrectionService::class),
                $document->load('items'), new SalesReturnCorrection([
                'reason' => 'Stock-credit regression test',
            ]),
                $proposal, 1, $groups, false
            ),
            $central, $variant
        );
    }

    public function test_verified_site_invoice_passes_deduction_guard(): void
    {
        $invoice = new Invoice();
        $invoice->external_order_id = 920002;
        $invoice->stock_deduction_status = 'deducted';
        $invoice->assertStockDeducted();
        $this->assertTrue(true);
    }

    public function test_legacy_internal_invoice_with_null_status_remains_eligible(): void
    {
        $invoice = new Invoice();
        $invoice->external_order_id = null;
        $invoice->stock_deduction_status = null;
        $invoice->assertStockDeducted();
        $this->assertTrue(true);
    }
}
