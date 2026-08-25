<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReturnDirectApplyWithoutInboundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->user = User::factory()->create();
    }

    public function test_return_only_return_warehouse_items_do_not_create_inbound_queue(): void
    {
        [$document] = $this->makeReturnDocument([
            ['destination' => 'return', 'quantity' => 3],
            ['destination' => 'return', 'quantity' => 2],
        ]);

        app(SalesReturnService::class)->apply($document, $this->user->id);

        $this->assertDatabaseCount('warehouse_inbound_receipts', 0);
    }

    public function test_mixed_return_queues_only_central_destination_items(): void
    {
        [$document, $items] = $this->makeReturnDocument([
            ['destination' => 'central', 'quantity' => 4],
            ['destination' => 'return', 'quantity' => 7],
        ]);

        app(SalesReturnService::class)->apply($document, $this->user->id);

        $receipt = WarehouseInboundReceipt::query()->with('items')->sole();

        $this->assertSame(
            SalesReturnDocument::STATUS_PENDING_WAREHOUSE,
            $document->fresh()->status
        );

        $this->assertDatabaseHas('warehouse_inbound_receipt_items', [
            'receipt_id' => $receipt->id,
            'source_item_id' => $items[0]->id,
        ]);

        $this->assertDatabaseMissing('warehouse_inbound_receipt_items', [
            'receipt_id' => $receipt->id,
            'source_item_id' => $items[1]->id,
        ]);
    }

    private function makeReturnDocument(array $rows): array
    {
        $category = Category::query()->create([
            'name' => 'Test Category '.Str::random(5),
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Test Product '.Str::random(5),
            'sku' => 'SKU-'.Str::random(5),
            'price' => 100,
            'stock' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Variant '.Str::random(5),
            'variant_code' => 'VAR-'.Str::random(5),
            'sell_price' => 100,
            'stock' => 0,
            'is_active' => true,
        ]);

        $central = Warehouse::query()->firstOrCreate(
            ['name' => 'انبار مرکزی'],
            ['type' => 'central', 'is_active' => true]
        );

        $return = Warehouse::query()->firstOrCreate(
            ['name' => 'انبار مرجوعی'],
            ['type' => 'return', 'is_active' => true]
        );

        $customer = Customer::query()->create([
            'name' => 'Test Customer',
            'mobile' => '0912'.random_int(1000000,9999999),
        ]);

        $invoice = Invoice::query()->create([
            'uuid' => 'TEST-'.Str::uuid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'status' => Invoice::STATUS_SHIPPED,
            'subtotal' => 10000,
            'total' => 10000,
        ]);

        $invoiceItems = collect($rows)->map(function ($row) use ($invoice, $product, $variant) {
            return $invoice->items()->create([
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 20,
                'price' => 100,
            ]);
        });

        $document = SalesReturnDocument::query()->create([
            'document_number' => 'SR-'.Str::random(8),
            'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
            'status' => SalesReturnDocument::STATUS_DRAFT,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'default_destination_warehouse_id' => $return->id,
            'created_by' => $this->user->id,
        ]);

        $items = collect($rows)->map(function ($row, $index) use ($document, $invoiceItems, $product, $variant, $central, $return) {
            $warehouse = $row['destination'] === 'central' ? $central : $return;
            $invoiceItem = $invoiceItems[$index];

            return $document->items()->create([
                'invoice_item_id' => $invoiceItem->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $product->name,
                'variant_name_snapshot' => $variant->variant_name,
                'item_source' => SalesReturnDocumentItem::SOURCE_INVOICE_ITEM,
                'item_condition' => SalesReturnDocumentItem::CONDITION_HEALTHY,
                'destination_warehouse_id' => $warehouse->id,
                'sold_quantity_snapshot' => 20,
                'return_quantity' => $row['quantity'],
                'refund_unit_price' => 100,
                'refund_amount' => $row['quantity'] * 100,
            ]);
        });

        return [$document->fresh('items'), $items];
    }
}
