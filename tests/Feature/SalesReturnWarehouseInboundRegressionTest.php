<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseStock;
use App\Services\SalesReturnService;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesReturnWarehouseInboundRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::findOrCreate('Owner', 'web'));
    }

    public function test_all_central_items_create_one_traceable_receipt_with_exact_total(): void
    {
        [$document, $items] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 5, 'destination' => 'central'],
        ]);

        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();

        $this->assertSame(WarehouseInboundReceipt::SOURCE_SALES_RETURN, $receipt->source_type);
        $this->assertSame($document->id, (int) $receipt->source_id);
        $this->assertSame(8, (int) $receipt->expected_quantity);
        $this->assertCount(2, $receipt->items);
        $this->assertSame($items->pluck('id')->sort()->values()->all(), $receipt->items->pluck('source_item_id')->sort()->values()->all());
    }

    public function test_all_return_items_create_no_central_queue_and_do_not_change_central_stock(): void
    {
        [$document, $items, $central] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'return'],
            ['quantity' => 5, 'destination' => 'return'],
        ]);

        app(SalesReturnService::class)->apply($document, $this->user->id);

        $this->assertDatabaseCount('warehouse_inbound_receipts', 0);
        $this->assertDatabaseCount('warehouse_inbound_receipt_items', 0);
        foreach ($items as $item) {
            $this->assertStock($central, $item, 0);
        }
    }

    public function test_mixed_destinations_ignore_header_and_queue_only_primary_central_items(): void
    {
        [$document, $items, $central, $return] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 5, 'destination' => 'return'],
            ['quantity' => 2, 'destination' => 'central'],
        ], 'return');

        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();

        $this->assertSame($return->id, (int) $document->default_destination_warehouse_id);
        $this->assertSame(5, (int) $receipt->expected_quantity);
        $this->assertDatabaseHas('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $items[0]->id, 'suggested_warehouse_id' => $central->id]);
        $this->assertDatabaseHas('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $items[2]->id, 'suggested_warehouse_id' => $central->id]);
        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $items[1]->id]);
        $this->assertDatabaseCount('warehouse_inbound_receipt_items', 2);
    }

    public function test_central_header_does_not_pull_return_or_secondary_central_items_into_primary_queue(): void
    {
        $secondary = Warehouse::query()->create(['name' => 'Secondary Central', 'type' => 'central', 'is_active' => true]);
        [$document, $items] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 5, 'destination' => 'return'],
            ['quantity' => 7, 'destination_id' => $secondary->id],
        ], 'central');

        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();

        $this->assertSame(3, (int) $receipt->expected_quantity);
        $this->assertSame([$items[0]->id], $receipt->items->pluck('source_item_id')->all());
        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', ['source_item_id' => $items[1]->id]);
        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', ['source_item_id' => $items[2]->id]);
    }

    public function test_apply_retry_and_direct_sync_are_idempotent(): void
    {
        [$document] = $this->returnFixture([
            ['quantity' => 8, 'destination' => 'central'],
            ['quantity' => 50, 'destination' => 'return'],
            ['quantity' => 7, 'destination' => 'central'],
        ]);
        $returns = app(SalesReturnService::class);
        $inbound = app(WarehouseInboundService::class);

        $returns->apply($document, $this->user->id);
        $returns->apply($document->fresh(), $this->user->id);
        $inbound->queueSalesReturn($document->fresh('items'), $this->user->id);

        $this->assertDatabaseCount('warehouse_inbound_receipts', 1);
        $this->assertDatabaseCount('warehouse_inbound_receipt_items', 2);
        $this->assertSame(15, (int) WarehouseInboundReceipt::query()->value('expected_quantity'));
    }

    public function test_pending_sync_handles_destination_quantity_deletion_and_addition(): void
    {
        [$document, $items, $central, $return] = $this->returnFixture([
            ['quantity' => 4, 'destination' => 'central'],
            ['quantity' => 2, 'destination' => 'central'],
        ]);
        $service = app(WarehouseInboundService::class);
        $receipt = $service->queueSalesReturn($document->fresh('items'), $this->user->id);

        $items[0]->update(['destination_warehouse_id' => $return->id]);
        $items[1]->update(['return_quantity' => 8]);
        $newItem = $this->appendItem($document, 3, $central);
        $service->queueSalesReturn($document->fresh('items'), $this->user->id);

        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $items[0]->id]);
        $this->assertDatabaseHas('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $items[1]->id, 'expected_quantity' => 8]);
        $this->assertDatabaseHas('warehouse_inbound_receipt_items', ['receipt_id' => $receipt->id, 'source_item_id' => $newItem->id, 'expected_quantity' => 3]);
        $this->assertSame(11, (int) $receipt->fresh()->expected_quantity);

        $items[1]->delete();
        $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', ['source_item_id' => $items[1]->id]);
        $this->assertSame(3, (int) $receipt->fresh()->expected_quantity);
    }

    public function test_return_to_central_sync_creates_one_receipt_and_central_to_return_removes_empty_pending_receipt(): void
    {
        [$document, $items, $central, $return] = $this->returnFixture([['quantity' => 4, 'destination' => 'return']]);
        $service = app(WarehouseInboundService::class);

        $this->assertNull($service->queueSalesReturn($document->fresh('items'), $this->user->id));
        $items[0]->update(['destination_warehouse_id' => $central->id]);
        $receipt = $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $this->assertDatabaseCount('warehouse_inbound_receipts', 1);
        $this->assertDatabaseCount('warehouse_inbound_receipt_items', 1);

        $items[0]->update(['destination_warehouse_id' => $return->id]);
        $this->assertNull($service->queueSalesReturn($document->fresh('items'), $this->user->id));
        $this->assertDatabaseMissing('warehouse_inbound_receipts', ['id' => $receipt->id]);
    }

    public function test_mixed_receive_updates_only_central_variants_and_creates_no_return_item_movement(): void
    {
        [$document, $items, $central] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 5, 'destination' => 'return'],
            ['quantity' => 2, 'destination' => 'central'],
        ]);
        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();
        $this->receiveExact($receipt, $central);

        $this->assertStock($central, $items[0], 3);
        $this->assertStock($central, $items[1], 0);
        $this->assertStock($central, $items[2], 2);
        $this->assertSame(2, StockMovement::query()->where('transaction_type', WarehouseInboundService::TRANSACTION_TYPE)->count());
        $this->assertFalse(StockMovement::query()->where('product_variant_id', $items[1]->product_variant_id)->exists());
    }

    public function test_received_receipt_is_historical_and_double_receive_cannot_duplicate_stock_or_movements(): void
    {
        [$document, $items, $central, $return] = $this->returnFixture([['quantity' => 5, 'destination' => 'central']]);
        $service = app(WarehouseInboundService::class);
        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();
        $service->receive($receipt, [[
            'id' => $receipt->items()->sole()->id,
            'accepted_quantity' => 3,
            'received_warehouse_id' => $central->id,
            'note' => 'short delivery',
        ]], $this->user->id, 'short delivery');
        $movementId = $receipt->items()->sole()->stock_movement_id;

        $items[0]->update(['destination_warehouse_id' => $return->id, 'return_quantity' => 9]);
        $service->queueSalesReturn($document->fresh('items'), $this->user->id);
        $this->assertSame(3, (int) $receipt->fresh()->accepted_quantity);
        $this->assertSame(5, (int) $receipt->items()->sole()->expected_quantity);
        $this->assertSame($movementId, $receipt->items()->sole()->stock_movement_id);

        try {
            $this->receiveExact($receipt->fresh(), $central);
            $this->fail('Second receive must fail.');
        } catch (ValidationException) {
            $this->assertStock($central, $items[0], 3);
            $this->assertDatabaseCount('stock_movements', 1);
        }
    }

    public function test_zero_under_and_over_expected_follow_current_discrepancy_rules(): void
    {
        foreach ([[0, 0], [7, 7], [12, 12]] as [$accepted, $expectedStock]) {
            [$document, $items, $central] = $this->returnFixture([['quantity' => 10, 'destination' => 'central']]);
            app(SalesReturnService::class)->apply($document, $this->user->id);
            $receipt = WarehouseInboundReceipt::query()->where('source_id', $document->id)->with('items')->sole();
            app(WarehouseInboundService::class)->receive($receipt, [[
                'id' => $receipt->items()->sole()->id,
                'accepted_quantity' => $accepted,
                'received_warehouse_id' => $central->id,
                'note' => 'audited discrepancy',
            ]], $this->user->id, 'audited discrepancy');

            $this->assertStock($central, $items[0], $expectedStock);
            $this->assertSame($accepted, (int) $receipt->fresh()->accepted_quantity);
            $this->assertSame(WarehouseInboundReceipt::STATUS_DISCREPANCY, $receipt->fresh()->status);
        }
    }

    public function test_invalid_second_receive_row_rolls_back_without_partial_stock(): void
    {
        [$document, $items, $central] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 2, 'destination' => 'central'],
        ]);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document->fresh('items'), $this->user->id);
        $rows = $receipt->items->map(fn ($item) => [
            'id' => $item->id,
            'accepted_quantity' => $item->expected_quantity,
            'received_warehouse_id' => $central->id,
            'note' => null,
        ])->all();
        $rows[1]['received_warehouse_id'] = 999999;

        try {
            app(WarehouseInboundService::class)->receive($receipt, $rows, $this->user->id);
            $this->fail('Invalid destination must fail atomically.');
        } catch (ValidationException) {
            $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertStock($central, $items[0], 0);
            $this->assertStock($central, $items[1], 0);
        }
    }

    public function test_pending_sales_return_cancel_is_explicitly_rejected_and_queue_remains_unchanged(): void
    {
        [$document] = $this->returnFixture([['quantity' => 3, 'destination' => 'central']]);
        app(SalesReturnService::class)->apply($document, $this->user->id);

        try {
            app(SalesReturnService::class)->cancelDraft($document->fresh(), $this->user->id, 'undo');
            $this->fail('Current business rule rejects cancelling a pending warehouse return.');
        } catch (ValidationException) {
            $this->assertSame(SalesReturnDocument::STATUS_PENDING_WAREHOUSE, $document->fresh()->status);
            $this->assertDatabaseCount('warehouse_inbound_receipts', 1);
            $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, WarehouseInboundReceipt::query()->value('status'));
        }
    }

    public function test_two_documents_with_similar_products_keep_receipt_and_item_lineage_separate(): void
    {
        [$firstDocument, $firstItems] = $this->returnFixture([['quantity' => 2, 'destination' => 'central']]);
        [$secondDocument, $secondItems] = $this->returnFixture([['quantity' => 4, 'destination' => 'central']]);
        $service = app(WarehouseInboundService::class);
        $firstReceipt = $service->queueSalesReturn($firstDocument->fresh('items'), $this->user->id);
        $secondReceipt = $service->queueSalesReturn($secondDocument->fresh('items'), $this->user->id);

        $this->assertNotSame($firstReceipt->id, $secondReceipt->id);
        $this->assertSame($firstDocument->id, (int) $firstReceipt->source_id);
        $this->assertSame($secondDocument->id, (int) $secondReceipt->source_id);
        $this->assertSame($firstItems[0]->id, (int) $firstReceipt->items()->sole()->source_item_id);
        $this->assertSame($secondItems[0]->id, (int) $secondReceipt->items()->sole()->source_item_id);
    }

    public function test_guest_and_user_without_page_permission_cannot_receive_but_authorized_user_can(): void
    {
        [$document, , $central] = $this->returnFixture([['quantity' => 1, 'destination' => 'central']]);
        app(SalesReturnService::class)->apply($document, $this->user->id);
        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();
        $item = $receipt->items()->sole();
        $payload = ['items' => [[
            'id' => $item->id,
            'accepted_quantity' => 1,
            'received_warehouse_id' => $central->id,
        ]]];

        $this->post(route('warehouse.inbound.receive', $receipt), $payload)->assertRedirect();
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser)->post(route('warehouse.inbound.receive', $receipt), $payload)->assertForbidden();
        $this->assertSame(WarehouseInboundReceipt::STATUS_PENDING, $receipt->fresh()->status);
        $this->actingAs($this->user)->post(route('warehouse.inbound.receive', $receipt), $payload)->assertRedirect(route('warehouse.inbound.index'));
        $this->assertSame(WarehouseInboundReceipt::STATUS_RECEIVED, $receipt->fresh()->status);
    }

    public function test_queue_endpoint_contains_central_item_snapshots_and_excludes_return_item(): void
    {
        [$document, $items] = $this->returnFixture([
            ['quantity' => 2, 'destination' => 'central'],
            ['quantity' => 3, 'destination' => 'return'],
        ]);
        $items[0]->update(['product_name_snapshot' => 'CENTRAL-ONLY-SNAPSHOT']);
        $items[1]->update(['product_name_snapshot' => 'RETURN-HIDDEN-SNAPSHOT']);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document->fresh('items.product', 'items.variant'), $this->user->id);

        $this->actingAs($this->user)->getJson(route('warehouse.inbound.show', $receipt))
            ->assertOk()
            ->assertSee('CENTRAL-ONLY-SNAPSHOT')
            ->assertDontSee('RETURN-HIDDEN-SNAPSHOT');
    }

    public function test_repair_dry_run_reports_invalid_item_apply_is_idempotent_and_received_history_is_untouched(): void
    {
        [$document, $items] = $this->returnFixture([
            ['quantity' => 3, 'destination' => 'central'],
            ['quantity' => 5, 'destination' => 'return'],
        ]);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($document->fresh('items'), $this->user->id);
        $this->insertCorruptQueueItem($receipt, $items[1]);
        $receipt->update(['expected_quantity' => 8]);

        [$historicalDocument, $historicalItems] = $this->returnFixture([
            ['quantity' => 2, 'destination' => 'central'],
            ['quantity' => 9, 'destination' => 'return'],
        ]);
        $historical = app(WarehouseInboundService::class)->queueSalesReturn($historicalDocument->fresh('items'), $this->user->id);
        $this->insertCorruptQueueItem($historical, $historicalItems[1]);
        $historical->update(['status' => WarehouseInboundReceipt::STATUS_RECEIVED, 'expected_quantity' => 11, 'accepted_quantity' => 2]);

        $this->artisan('warehouse:repair-sales-return-inbound --dry-run')
            ->expectsOutputToContain('remove source_item_ids ['.$items[1]->id.']')
            ->assertSuccessful();
        $this->assertSame(2, $receipt->items()->count());
        $this->assertSame(8, (int) $receipt->fresh()->expected_quantity);

        $this->artisan('warehouse:repair-sales-return-inbound --apply')->assertSuccessful();
        $this->assertSame([$items[0]->id], $receipt->items()->pluck('source_item_id')->all());
        $this->assertSame(3, (int) $receipt->fresh()->expected_quantity);
        $this->assertSame(2, $historical->items()->count());
        $this->assertSame(11, (int) $historical->fresh()->expected_quantity);

        $this->artisan('warehouse:repair-sales-return-inbound --apply')
            ->expectsOutputToContain('0 pending receipt(s) repaired')
            ->assertSuccessful();
        $this->assertSame([$items[0]->id], $receipt->items()->pluck('source_item_id')->all());
    }

    private function returnFixture(array $rows, string $header = 'central'): array
    {
        $central = Warehouse::query()->firstOrCreate(['type' => 'central', 'name' => 'انبار مرکزی'], ['is_active' => true]);
        $return = Warehouse::query()->firstOrCreate(['type' => 'return', 'name' => 'انبار مرجوعی'], ['is_active' => true]);
        $customer = Customer::query()->create([
            'first_name' => 'Regression',
            'last_name' => Str::random(8),
            'mobile' => '09'.random_int(100000000, 999999999),
        ]);
        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'customer_name' => 'Regression Customer',
            'status' => Invoice::STATUS_SHIPPED,
            'subtotal' => 100000,
            'total' => 100000,
        ]);
        $document = SalesReturnDocument::query()->create([
            'document_number' => 'SR-'.Str::random(12),
            'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
            'status' => SalesReturnDocument::STATUS_DRAFT,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'default_destination_warehouse_id' => $header === 'return' ? $return->id : $central->id,
            'total_quantity' => collect($rows)->sum('quantity'),
            'items_count' => count($rows),
            'total_refund_amount' => collect($rows)->sum('quantity') * 100,
            'created_by' => $this->user->id,
        ]);

        $items = collect();
        foreach ($rows as $index => $row) {
            $destinationId = $row['destination_id'] ?? ($row['destination'] === 'return' ? $return->id : $central->id);
            $items->push($this->appendItem($document, (int) $row['quantity'], Warehouse::query()->findOrFail($destinationId), $invoice, $index));
        }

        return [$document, $items, $central, $return, $invoice];
    }

    private function appendItem(SalesReturnDocument $document, int $quantity, Warehouse $destination, ?Invoice $invoice = null, int $index = 99): SalesReturnDocumentItem
    {
        $invoice ??= $document->invoice;
        $category = Category::query()->create(['name' => 'Category '.Str::random(8)]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Product '.Str::random(8), 'sku' => 'P-'.Str::random(10), 'price' => 100, 'stock' => 0]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'Variant '.$index, 'variant_code' => 'V-'.Str::random(10), 'sell_price' => 100, 'stock' => 0, 'is_active' => true, 'sales_enabled' => true]);
        $invoiceItem = $invoice->items()->create(['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => max($quantity, 100), 'price' => 100]);

        return $document->items()->create([
            'invoice_item_id' => $invoiceItem->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => $variant->variant_name,
            'sku_snapshot' => $variant->variant_code,
            'item_source' => SalesReturnDocumentItem::SOURCE_INVOICE_ITEM,
            'item_condition' => $destination->type === 'return' ? SalesReturnDocumentItem::CONDITION_DAMAGED : SalesReturnDocumentItem::CONDITION_HEALTHY,
            'destination_warehouse_id' => $destination->id,
            'sold_quantity_snapshot' => max($quantity, 100),
            'previously_returned_quantity_snapshot' => 0,
            'return_quantity' => $quantity,
            'unit_price_snapshot' => 100,
            'refund_unit_price' => 100,
            'refund_amount' => $quantity * 100,
            'sort_order' => $index + 1,
        ]);
    }

    private function receiveExact(WarehouseInboundReceipt $receipt, Warehouse $warehouse): void
    {
        $rows = $receipt->items()->get()->map(fn ($item) => [
            'id' => $item->id,
            'accepted_quantity' => $item->expected_quantity,
            'received_warehouse_id' => $warehouse->id,
            'note' => null,
        ])->all();
        app(WarehouseInboundService::class)->receive($receipt, $rows, $this->user->id);
    }

    private function insertCorruptQueueItem(WarehouseInboundReceipt $receipt, SalesReturnDocumentItem $item): void
    {
        $receipt->items()->create([
            'source_item_type' => SalesReturnDocumentItem::class,
            'source_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'expected_quantity' => $item->return_quantity,
            'accepted_quantity' => 0,
            'suggested_warehouse_id' => $item->destination_warehouse_id,
            'condition' => $item->item_condition,
            'reason' => 'sales_return',
        ]);
    }

    private function assertStock(Warehouse $warehouse, SalesReturnDocumentItem $item, int $expected): void
    {
        $this->assertSame($expected, (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $item->product_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->value('quantity'));
    }
}
