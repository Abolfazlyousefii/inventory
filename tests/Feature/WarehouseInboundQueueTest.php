<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseStock;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseInboundQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->user = User::factory()->create();
    }

    public function test_invoice_reduction_does_not_change_stock_before_approval_and_adds_only_accepted_quantity(): void
    {
        [$invoice, $item, $central] = $this->invoiceFixture(5);
        $receipt = $this->queueAdjustment($invoice, $item, 2, 'quantity_decreased');

        $this->assertStock($central, $item, 0);
        $this->receive($receipt, 2, $central);

        $this->assertStock($central, $item, 2);
        $this->assertSame(2, (int) ProductVariant::query()->findOrFail($item->variant_id)->stock);
        $this->assertSame(2, (int) Product::query()->findOrFail($item->product_id)->stock);
        $this->assertSame(WarehouseInboundReceipt::STATUS_RECEIVED, $receipt->fresh()->status);
        $this->assertSame(1, StockMovement::query()->where('transaction_type', WarehouseInboundService::TRANSACTION_TYPE)->count());
    }

    public function test_same_source_operation_key_does_not_create_duplicate_receipt(): void
    {
        [$invoice, $item] = $this->invoiceFixture(5);
        $first = $this->queueAdjustment($invoice, $item, 2, 'quantity_decreased', 'stable-operation');
        $second = $this->queueAdjustment($invoice, $item, 2, 'quantity_decreased', 'stable-operation');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WarehouseInboundReceipt::query()->count());
    }

    public function test_zero_and_over_expected_receipts_finalize_with_audited_discrepancy(): void
    {
        [$invoice, $item, $central] = $this->invoiceFixture(5);
        $zero = $this->queueAdjustment($invoice, $item, 2, 'physical_shortage', 'zero');
        $this->receive($zero, 0, $central, 'کالا به‌صورت فیزیکی پیدا نشد.');

        $this->assertStock($central, $item, 0);
        $this->assertSame(WarehouseInboundReceipt::STATUS_DISCREPANCY, $zero->fresh()->status);
        $this->assertSame(-2, $zero->fresh()->difference);

        $over = $this->queueAdjustment($invoice, $item, 2, 'invoice_correction', 'over');
        try {
            $this->receive($over, 3, $central);
            $this->fail('A discrepancy without a note must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_note', $exception->errors());
        }

        $this->receive($over->fresh(), 3, $central, 'یک عدد اضافه در تحویل فیزیکی مشاهده شد.');
        $this->assertStock($central, $item, 3);
        $this->assertSame(1, $over->fresh()->difference);
    }

    public function test_invoice_cancel_is_queued_and_double_approval_cannot_duplicate_stock(): void
    {
        [$invoice, $first, $central] = $this->invoiceFixture(3);
        [, $secondProduct, $secondVariant] = $this->catalog();
        $second = $invoice->items()->create([
            'product_id' => $secondProduct->id,
            'variant_id' => $secondVariant->id,
            'quantity' => 2,
            'price' => 200,
        ]);

        $service = app(WarehouseInboundService::class);
        $receipt = $service->queueInvoiceCancellation($invoice, $this->user->id, 'لغو کامل فاکتور');
        $this->assertStock($central, $first, 0);
        $this->assertStock($central, $second, 0);

        $payload = $receipt->items->map(fn ($receiptItem) => [
            'id' => $receiptItem->id,
            'accepted_quantity' => $receiptItem->source_item_id === $first->id ? 2 : 1,
            'received_warehouse_id' => $central->id,
            'note' => null,
        ])->all();
        $service->receive($receipt, $payload, $this->user->id, 'بخشی از اقلام تحویل نشد.');

        $this->assertStock($central, $first, 2);
        $this->assertStock($central, $second, 1);

        $finalReceipt = $receipt->fresh('items');
        $this->assertSame($this->user->id, $finalReceipt->reviewed_by);
        $this->assertNotNull($finalReceipt->reviewed_at);
        $this->assertNotNull($finalReceipt->review_note);
        $this->assertSame(-2, $finalReceipt->difference);
        $this->assertSame([2, 1], $finalReceipt->items->pluck('accepted_quantity')->all());
        $this->assertSame([2, 1], StockMovement::query()
            ->where('transaction_type', WarehouseInboundService::TRANSACTION_TYPE)
            ->orderBy('id')->pluck('quantity')->map(fn ($quantity) => (int) $quantity)->all());

        $this->expectException(ValidationException::class);
        try {
            $service->receive($receipt->fresh(), $payload, $this->user->id, 'تلاش تکراری');
        } finally {
            $this->assertStock($central, $first, 2);
            $this->assertStock($central, $second, 1);
        }
    }

    public function test_sales_return_with_only_return_warehouse_items_does_not_create_central_receipt(): void
    {
        [$document, $invoiceItem, $returnWarehouse] = $this->salesReturnFixture(expected: 5, sold: 7);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document, $this->user->id);

        $this->assertNull($receipt);
        $this->assertSame(0, WarehouseInboundReceipt::query()->count());
        $this->assertStock($returnWarehouse, $invoiceItem, 0);
    }

    public function test_mixed_sales_return_queues_only_central_items_and_is_idempotent(): void
    {
        [$document, , $central] = $this->salesReturnFixture(expected: 3, sold: 10, central: true);
        $first = $document->items()->firstOrFail();
        [, , , , $returnWarehouse] = $this->catalog();
        $returnItem = $document->items()->create($this->returnItemAttributes($first, 5, $returnWarehouse));
        $third = $document->items()->create($this->returnItemAttributes($first, 2, $central));

        $service = app(WarehouseInboundService::class);
        $receipt = $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $again = $service->queueSalesReturn($document->fresh('items'), $this->user->id);

        $this->assertSame($receipt->id, $again->id);
        $this->assertSame(1, WarehouseInboundReceipt::query()->count());
        $this->assertSame(5, (int) $receipt->fresh()->expected_quantity);
        $this->assertSame([$first->id, $third->id], $receipt->items()->orderBy('source_item_id')->pluck('source_item_id')->all());
        $this->assertFalse($receipt->items()->where('source_item_id', $returnItem->id)->exists());
    }

    public function test_pending_sales_return_receipt_synchronizes_destination_changes_but_finalized_receipt_is_immutable(): void
    {
        [$document, , $central] = $this->salesReturnFixture(expected: 3, sold: 10, central: true);
        $item = $document->items()->firstOrFail();
        [, , , , $returnWarehouse] = $this->catalog();
        $service = app(WarehouseInboundService::class);
        $receipt = $service->queueSalesReturn($document->fresh('items'), $this->user->id);

        $item->update(['destination_warehouse_id' => $returnWarehouse->id]);
        $this->assertNull($service->queueSalesReturn($document->fresh('items'), $this->user->id));
        $this->assertDatabaseMissing('warehouse_inbound_receipts', ['id' => $receipt->id]);

        $item->update(['destination_warehouse_id' => $central->id]);
        $newReceipt = $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $newReceipt->update(['status' => WarehouseInboundReceipt::STATUS_RECEIVED]);
        $item->update(['destination_warehouse_id' => $returnWarehouse->id]);

        $same = $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $this->assertSame($newReceipt->id, $same->id);
        $this->assertSame(1, $same->items()->count());
    }

    public function test_repair_command_is_dry_run_safe_and_repairs_only_pending_sales_return_items(): void
    {
        [$document, , $central] = $this->salesReturnFixture(expected: 3, sold: 10, central: true);
        $first = $document->items()->firstOrFail();
        [, , , , $returnWarehouse] = $this->catalog();
        $returnItem = $document->items()->create($this->returnItemAttributes($first, 5, $returnWarehouse));
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document->fresh('items'), $this->user->id);
        $receipt->items()->create($this->queueItemAttributes($returnItem));
        $receipt->update(['expected_quantity' => 8]);

        $this->artisan('warehouse:repair-sales-return-inbound --dry-run')->assertSuccessful();
        $this->assertSame(2, $receipt->items()->count());
        $this->assertSame(8, (int) $receipt->fresh()->expected_quantity);

        $this->artisan('warehouse:repair-sales-return-inbound --apply')->assertSuccessful();
        $this->assertSame([$first->id], $receipt->items()->pluck('source_item_id')->all());
        $this->assertSame(3, (int) $receipt->fresh()->expected_quantity);
    }

    public function test_sales_return_exact_receipt_finalizes_stock_and_finance_for_expected_quantity(): void
    {
        [$document, $invoiceItem, $central] = $this->salesReturnFixture(expected: 5, sold: 5, central: true);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document, $this->user->id);

        $this->receive($receipt, 5, $central);

        $this->assertStock($central, $invoiceItem, 5);
        $this->assertSame(500, $document->fresh()->total_refund_amount);
        $this->assertSame(WarehouseInboundReceipt::STATUS_RECEIVED, $receipt->fresh()->status);
    }

    public function test_sales_return_can_accept_more_than_expected_within_real_returnable_limit(): void
    {
        [$document, $invoiceItem, $central] = $this->salesReturnFixture(expected: 5, sold: 7, central: true);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document, $this->user->id);

        $this->receive($receipt, 6, $central, 'یک عدد بیشتر از ثبت اولیه تحویل شد.');

        $this->assertStock($central, $invoiceItem, 6);
        $this->assertSame(600, $document->fresh()->total_refund_amount);
        $this->assertSame(1, $receipt->fresh()->difference);
    }

    public function test_sales_return_rejects_quantity_above_real_returnable_limit_without_side_effects(): void
    {
        [$document, $invoiceItem, $central] = $this->salesReturnFixture(expected: 5, sold: 5, central: true);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document, $this->user->id);

        try {
            $this->receive($receipt, 6, $central, 'شش عدد تحویل شد.');
            $this->fail('Quantity above the sold amount must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertStock($central, $invoiceItem, 0);
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
        $this->assertSame(SalesReturnDocument::STATUS_PENDING_WAREHOUSE, $document->fresh()->status);
        $this->assertSame(0, CustomerLedger::query()->count());
    }

    private function queueAdjustment(
        Invoice $invoice,
        InvoiceItem $item,
        int $quantity,
        string $reason,
        ?string $operationKey = null
    ): WarehouseInboundReceipt {
        $service = app(WarehouseInboundService::class);
        $line = $service->invoiceItemLine($item, $quantity, WarehouseStockService::centralWarehouseId(), null, $reason);

        return $service->queueInvoiceAdjustment(
            $invoice,
            [$line],
            $this->user->id,
            $reason,
            $operationKey
        );
    }

    private function receive(
        WarehouseInboundReceipt $receipt,
        int $accepted,
        Warehouse $warehouse,
        ?string $note = null
    ): WarehouseInboundReceipt {
        $item = $receipt->items()->firstOrFail();

        return app(WarehouseInboundService::class)->receive($receipt, [[
            'id' => $item->id,
            'accepted_quantity' => $accepted,
            'received_warehouse_id' => $warehouse->id,
            'note' => $note,
        ]], $this->user->id, $note);
    }

    private function invoiceFixture(int $quantity): array
    {
        [, $product, $variant, $central] = $this->catalog();
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_name' => 'مشتری تست',
            'status' => Invoice::STATUS_COLLECTING,
            'subtotal' => $quantity * 100,
            'total' => $quantity * 100,
        ]);
        $item = $invoice->items()->create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'price' => 100,
        ]);

        return [$invoice, $item, $central];
    }

    private function salesReturnFixture(int $expected, int $sold, bool $central = false): array
    {
        [$category, $product, $variant, $centralWarehouse, $returnWarehouse] = $this->catalog();
        $customer = new Customer;
        $customer->forceFill([
            'first_name' => 'مشتری',
            'last_name' => 'برگشتی',
            'mobile' => '09'.random_int(100000000, 999999999),
        ])->save();
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'customer_name' => 'مشتری برگشتی',
            'status' => Invoice::STATUS_SHIPPED,
            'subtotal' => $sold * 100,
            'total' => $sold * 100,
        ]);
        $invoiceItem = $invoice->items()->create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => $sold,
            'price' => 100,
        ]);
        $destination = $central ? $centralWarehouse : $returnWarehouse;
        $document = SalesReturnDocument::query()->create([
            'document_number' => 'SR-'.Str::random(10),
            'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
            'status' => SalesReturnDocument::STATUS_PENDING_WAREHOUSE,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'default_destination_warehouse_id' => $destination->id,
            'total_quantity' => $expected,
            'items_count' => 1,
            'total_refund_amount' => $expected * 100,
            'created_by' => $this->user->id,
        ]);
        $document->items()->create([
            'invoice_item_id' => $invoiceItem->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => $variant->variant_name,
            'sku_snapshot' => $variant->variant_code,
            'item_source' => SalesReturnDocumentItem::SOURCE_INVOICE_ITEM,
            'item_condition' => $central ? SalesReturnDocumentItem::CONDITION_HEALTHY : SalesReturnDocumentItem::CONDITION_DAMAGED,
            'destination_warehouse_id' => $destination->id,
            'sold_quantity_snapshot' => $sold,
            'previously_returned_quantity_snapshot' => 0,
            'return_quantity' => $expected,
            'unit_price_snapshot' => 100,
            'refund_unit_price' => 100,
            'refund_amount' => $expected * 100,
        ]);

        return [$document, $invoiceItem, $destination, $category];
    }

    private function returnItemAttributes(SalesReturnDocumentItem $source, int $quantity, Warehouse $destination): array
    {
        return [
            'invoice_item_id' => null,
            'product_id' => $source->product_id,
            'product_variant_id' => $source->product_variant_id,
            'product_name_snapshot' => $source->product_name_snapshot,
            'variant_name_snapshot' => $source->variant_name_snapshot,
            'sku_snapshot' => $source->sku_snapshot,
            'item_source' => $source->item_source,
            'item_condition' => $destination->type === 'return' ? SalesReturnDocumentItem::CONDITION_DAMAGED : SalesReturnDocumentItem::CONDITION_HEALTHY,
            'destination_warehouse_id' => $destination->id,
            'sold_quantity_snapshot' => 10,
            'previously_returned_quantity_snapshot' => 0,
            'return_quantity' => $quantity,
            'unit_price_snapshot' => 100,
            'refund_unit_price' => 100,
            'refund_amount' => $quantity * 100,
        ];
    }

    private function queueItemAttributes(SalesReturnDocumentItem $item): array
    {
        return [
            'source_item_type' => SalesReturnDocumentItem::class,
            'source_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'expected_quantity' => $item->return_quantity,
            'accepted_quantity' => 0,
            'suggested_warehouse_id' => $item->destination_warehouse_id,
            'condition' => $item->item_condition,
            'reason' => 'sales_return',
        ];
    }

    private function catalog(): array
    {
        $category = Category::query()->create(['name' => 'دسته '.Str::random(8)]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'کالا '.Str::random(8),
            'sku' => 'P-'.Str::random(10),
            'price' => 100,
            'stock' => 0,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'تنوع '.Str::random(8),
            'variant_code' => 'V-'.Str::random(10),
            'sell_price' => 100,
            'stock' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
        $central = Warehouse::query()->firstOrCreate(
            ['type' => 'central', 'name' => 'انبار مرکزی'],
            ['is_active' => true]
        );
        $return = Warehouse::query()->firstOrCreate(
            ['type' => 'return', 'name' => 'انبار مرجوعی'],
            ['is_active' => true]
        );

        return [$category, $product, $variant, $central, $return];
    }

    private function assertStock(Warehouse $warehouse, InvoiceItem $item, int $expected): void
    {
        $this->assertSame($expected, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $item->product_id)
            ->where('product_variant_id', $item->variant_id)
            ->value('quantity'));
    }
}
