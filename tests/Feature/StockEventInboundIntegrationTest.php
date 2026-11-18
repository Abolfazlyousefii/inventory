<?php

namespace Tests\Feature;

use App\Events\StockChangeRequested;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseInboundReceiptItem;
use App\Models\WarehouseStock;
use App\Services\WarehouseInboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class StockEventInboundIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->actor = User::factory()->create();
    }

    public function test_inbound_receipt_event_is_handled_once_and_records_existing_stock_movement(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(3);
        $observed = 0;
        Event::listen(StockChangeRequested::class, function (StockChangeRequested $event) use (&$observed): void {
            $observed++;
        });

        $service = app(WarehouseInboundService::class);
        $service->receive($receipt, [[
            'id' => $receipt->items->sole()->id,
            'accepted_quantity' => 3,
            'received_warehouse_id' => $warehouse->id,
            'note' => null,
        ]], (int) $this->actor->id);

        $this->assertSame(1, $observed);
        $this->assertSame(3, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertSame(1, StockMovement::query()
            ->where('transaction_type', WarehouseInboundService::TRANSACTION_TYPE)->count());
        $this->assertSame((int) $this->actor->id, (int) StockMovement::query()
            ->where('transaction_type', WarehouseInboundService::TRANSACTION_TYPE)
            ->sole()->user_id);
        $this->assertSame((int) $this->actor->id, (int) $receipt->fresh()->reviewed_by);
        $this->assertSame(WarehouseInboundReceipt::STATUS_RECEIVED, $receipt->fresh()->status);
        $this->assertNotNull($receipt->items->sole()->fresh()->stock_movement_id);
    }

    public function test_finalized_receipt_cannot_dispatch_another_stock_increase(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(2);
        $item = $receipt->items->sole();
        app(WarehouseInboundService::class)->receive($receipt, [[
            'id' => $item->id,
            'accepted_quantity' => 2,
            'received_warehouse_id' => $warehouse->id,
            'note' => null,
        ]], (int) $this->actor->id);

        try {
            DB::transaction(function () use ($receipt, $item, $warehouse): void {
                event($this->stockEvent($receipt, $item, $warehouse, 2));
            });
            $this->fail('A second inbound stock change must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(2, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_event_stock_and_movement_roll_back_with_source_transaction(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(4);
        $item = $receipt->items->sole();

        try {
            DB::transaction(function () use ($receipt, $item, $warehouse): void {
                $event = $this->stockEvent($receipt, $item, $warehouse, 4);
                event($event);
                $this->assertNotNull($event->movement()->id);
                throw new RuntimeException('Force source document rollback');
            });
            $this->fail('The transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force source document rollback', $exception->getMessage());
        }

        $this->assertSame(0, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
    }

    public function test_dispatching_the_same_event_twice_rolls_back_the_first_change(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(2);
        $item = $receipt->items->sole();
        $event = $this->stockEvent($receipt, $item, $warehouse, 2);

        try {
            DB::transaction(function () use ($event): void {
                event($event);
                event($event);
            });
            $this->fail('The same event object must not apply stock twice.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('more than once', $exception->getMessage());
        }

        $this->assertSame(0, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
    }

    public function test_two_separate_events_for_the_same_receipt_item_cannot_double_credit_stock(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(2);
        $item = $receipt->items->sole();

        try {
            DB::transaction(function () use ($receipt, $item, $warehouse): void {
                event($this->stockEvent($receipt, $item, $warehouse, 2));
                event($this->stockEvent($receipt, $item, $warehouse, 2));
            });
            $this->fail('A second event for the same receipt item must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }

        $this->assertSame(0, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
    }

    public function test_inbound_event_requires_an_actor_before_changing_stock(): void
    {
        [$receipt, $warehouse, $variant] = $this->pendingReceipt(2);
        $item = $receipt->items->sole();
        $event = new StockChangeRequested(
            warehouseId: $warehouse->id,
            productId: (int) $item->product_id,
            variantId: (int) $item->product_variant_id,
            delta: 2,
            transactionType: WarehouseInboundService::TRANSACTION_TYPE,
            reason: StockMovement::REASON_RETURN,
            referenceType: WarehouseInboundReceiptItem::class,
            referenceId: (int) $item->id,
            reference: $receipt->receipt_number,
            actorId: null,
        );

        try {
            DB::transaction(fn () => event($event));
            $this->fail('An inbound stock event without a reviewer must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('actor', $exception->getMessage());
        }

        $this->assertSame(0, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
    }

    private function stockEvent(
        WarehouseInboundReceipt $receipt,
        WarehouseInboundReceiptItem $item,
        Warehouse $warehouse,
        int $quantity
    ): StockChangeRequested {
        return new StockChangeRequested(
            warehouseId: $warehouse->id,
            productId: (int) $item->product_id,
            variantId: (int) $item->product_variant_id,
            delta: $quantity,
            transactionType: WarehouseInboundService::TRANSACTION_TYPE,
            reason: StockMovement::REASON_RETURN,
            referenceType: WarehouseInboundReceiptItem::class,
            referenceId: (int) $item->id,
            reference: $receipt->receipt_number,
            actorId: (int) $this->actor->id,
        );
    }

    private function pendingReceipt(int $quantity): array
    {
        $category = Category::query()->create(['name' => 'Stock event test '.Str::random(8)]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Stock event product '.Str::random(8),
            'sku' => 'S-'.Str::random(10),
            'price' => 100,
            'stock' => 0,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Event variant',
            'variant_code' => 'SV-'.Str::random(10),
            'sell_price' => 100,
            'stock' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['type' => 'central', 'name' => 'انبار مرکزی'],
            ['is_active' => true]
        );
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_name' => 'Stock event test customer',
            'status' => Invoice::STATUS_COLLECTING,
            'subtotal' => $quantity * 100,
            'total' => $quantity * 100,
        ]);
        $invoiceItem = $invoice->items()->create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'price' => 100,
        ]);
        $service = app(WarehouseInboundService::class);
        $receipt = $service->queueInvoiceAdjustment(
            $invoice,
            [$service->invoiceItemLine($invoiceItem, $quantity, $warehouse->id, null, 'invoice_correction')],
            null,
            'invoice_correction',
            'stock-event-test-'.Str::uuid(),
        );
        $this->assertNotNull($receipt);

        return [$receipt->fresh('items'), $warehouse, $variant];
    }
}
