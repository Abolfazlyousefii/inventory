<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherSalesReturnController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentRevision;
use App\Models\Warehouse;
use App\Services\Commissions\CommissionReturnSyncOutboxService;
use App\Services\SalesReturnService;
use App\Services\WarehouseInboundService;
use App\Support\InboundReviewVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PendingSalesReturnEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/purchase-returns-20261011'));
        Http::fake();
        $models = ['SalesReturnDocument', 'SalesReturnDocumentItem', 'SalesReturnDocumentRevision', 'WarehouseInboundReceipt', 'WarehouseInboundReceiptItem', 'Customer', 'CustomerLedger', 'ActivityLog', 'StockMovement', 'Warehouse', 'WarehouseStock', 'Product', 'ProductVariant', 'Category', 'Invoice', 'InvoiceItem', 'WarehouseTransfer', 'WarehouseTransferItem', 'Color', 'ModelList', 'User'];
        foreach ($models as $name) {
            $class = 'App\\Models\\'.$name;
            $m = new $class;
            $table = $m->getTable();
            if (Schema::hasTable($table)) {
                continue;
            }$fields = array_unique(array_merge($m->getFillable(), ['created_at', 'updated_at', 'deleted_at']));
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->id();
                foreach ($fields as $f) {
                    if ($f !== 'id') {
                        $t->text($f)->nullable();
                    }
                }
            });
        }
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('type')->unique();
            $t->integer('last_number')->default(0);
            $t->timestamps();
        });
        $this->mock(CommissionReturnSyncOutboxService::class, fn ($m) => $m->shouldReceive('stage')->andReturnNull());
        Warehouse::create(['id' => 1, 'name' => 'انبار مرکزی', 'type' => 'central', 'is_active' => true]);
        Warehouse::create(['id' => 2, 'name' => 'مرجوعی', 'type' => 'return', 'is_active' => true]);
        Customer::create(['name' => 'مشتری نمونه', 'mobile' => '09121111111', 'opening_balance' => 0]);
        $p = Product::create(['name' => 'کالای نمونه', 'stock' => 0, 'reserved' => 0]);
        ProductVariant::create(['product_id' => $p->id, 'variant_name' => 'مشکی', 'variant_code' => '123', 'stock' => 0, 'reserved' => 0]);
    }

    private function fixture(): array
    {
        $d = SalesReturnDocument::create(['document_number' => '00001', 'source_type' => 'sazeh_hesab', 'status' => 'pending_warehouse', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => 1, 'items_count' => 1, 'total_quantity' => 2, 'total_refund_amount' => 200, 'return_reason' => 'other']);
        $d->items()->create(['item_source' => 'existing_product', 'product_id' => 1, 'product_variant_id' => 1, 'product_name_snapshot' => 'کالای نمونه', 'variant_name_snapshot' => 'مشکی', 'return_quantity' => 2, 'refund_unit_price' => 100, 'refund_amount' => 200, 'destination_warehouse_id' => 1, 'item_condition' => 'healthy', 'sort_order' => 1]);
        $receipt = app(WarehouseInboundService::class)->queueSalesReturn($d->fresh('items'), 1);

        return [$d->fresh('items'), $receipt];
    }

    private function payload($d, int $qty = 3, int $warehouse = 1): array
    {
        return ['document_version' => InboundReviewVersion::token($d), 'adjustment_reason' => 'اصلاح تعداد و وضعیت کالا', 'source_type' => 'sazeh_hesab', 'customer_id' => 1, 'external_invoice_number' => 'OLD-1', 'default_destination_warehouse_id' => $warehouse, 'items' => [['item_source' => 'existing_product', 'product_variant_id' => 1, 'return_quantity' => $qty, 'refund_unit_price' => 100, 'destination_warehouse_id' => $warehouse, 'item_condition' => $warehouse === 1 ? 'healthy' : 'defective']]];
    }

    private function rejects(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail('Expected validation rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_pending_edit_replaces_receipt_atomically_and_rejects_old_warehouse_review(): void
    {
        [$d,$r] = $this->fixture();
        $oldToken = InboundReviewVersion::token($r);
        $oldRows = $r->items->map(fn ($i) => ['id' => $i->id, 'accepted_quantity' => 2, 'received_warehouse_id' => 1])->all();
        $new = app(SalesReturnService::class)->updatePending($d, $this->payload($d), 1);
        $r = $r->fresh('items');
        $this->assertSame(3, (int) $r->expected_quantity);
        $this->assertSame('00001', $new->document_number);
        $this->assertSame('pending_warehouse', $new->status);
        $this->assertDatabaseCount('warehouse_inbound_receipts', 1);
        $this->assertDatabaseCount('sales_return_document_revisions', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('customer_ledgers', 0);
        $this->rejects(fn () => app(WarehouseInboundService::class)->receive($r, $oldRows, 1, null, $oldToken), 'receipt');
        $this->assertSame('pending', $r->fresh()->status);
        $this->rejects(fn () => app(SalesReturnService::class)->updatePending($new, $this->payload($d), 1), 'document');
    }

    public function test_current_review_accepts_once_and_applies_only_the_revised_quantity(): void
    {
        [$d,$r] = $this->fixture();
        app(SalesReturnService::class)->updatePending($d, $this->payload($d), 1);
        $r = $r->fresh('items');
        $token = InboundReviewVersion::token($r);
        $rows = $r->items->map(fn ($i) => ['id' => $i->id, 'accepted_quantity' => 3, 'received_warehouse_id' => 1])->all();
        $result = app(WarehouseInboundService::class)->receive($r, $rows, 1, null, $token);
        $this->assertSame('received', $result->status);
        $this->assertSame(3, (int) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        $this->assertSame('applied', $d->fresh()->status);
        $this->rejects(fn () => app(WarehouseInboundService::class)->receive($r, $rows, 1, null, $token), 'receipt');
        $this->rejects(fn () => app(SalesReturnService::class)->updatePending($d, $this->payload($d), 1), 'document');
    }

    public function test_removing_all_central_lines_cancels_receipt_without_losing_its_history(): void
    {
        [$d,$r] = $this->fixture();
        $updated = app(SalesReturnService::class)->updatePending($d, $this->payload($d, 2, 2), 1);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertDatabaseCount('warehouse_inbound_receipt_items', 1);
        $this->assertSame('applied', $updated->status);
    }

    public function test_missing_reason_changed_source_and_invalid_quantity_roll_back(): void
    {
        [$d,$r] = $this->fixture();
        foreach (['adjustment_reason' => '', 'customer_id' => 99] as $field => $value) {
            $data = $this->payload($d);
            $data[$field] = $value;
            $this->rejects(fn () => app(SalesReturnService::class)->updatePending($d, $data, 1), $field === 'customer_id' ? 'document' : $field);
        }$data = $this->payload($d);
        $data['items'][0]['product_variant_id'] = 999;
        $this->expectException(ModelNotFoundException::class);
        try {
            app(SalesReturnService::class)->updatePending($d, $data, 1);
        } finally {
            $this->assertSame(2, (int) $r->fresh()->expected_quantity);
            $this->assertSame(2, (int) $d->fresh()->total_quantity);
        }
    }

    public function test_internal_return_excludes_itself_but_respects_other_pending_returns(): void
    {
        [$d,$r] = $this->fixture();
        $invoice = Invoice::create(['uuid' => '01884', 'customer_id' => 1, 'status' => 'shipped', 'subtotal' => 1000, 'total' => 1000, 'discount_amount' => 0, 'invoice_discount_amount' => 0, 'shipping_price' => 0]);
        $sold = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => 1, 'variant_id' => 1, 'quantity' => 10, 'price' => 100, 'line_total' => 1000, 'line_discount_amount' => 0]);
        $d->update(['source_type' => 'internal_invoice', 'invoice_id' => $invoice->id, 'external_invoice_number' => null]);
        $d->items()->first()->update(['item_source' => 'invoice_item', 'invoice_item_id' => $sold->id, 'sold_quantity_snapshot' => 10]);
        $other = SalesReturnDocument::create(['document_number' => '00002', 'source_type' => 'internal_invoice', 'status' => 'pending_warehouse', 'customer_id' => 1, 'invoice_id' => $invoice->id]);
        $other->items()->create(['invoice_item_id' => $sold->id, 'return_quantity' => 6, 'refund_amount' => 600]);
        $d = $d->fresh('items');
        $data = $this->payload($d, 3);
        $data['source_type'] = 'internal_invoice';
        $data['invoice_id'] = $invoice->id;
        unset($data['external_invoice_number']);
        $data['items'][0]['item_source'] = 'invoice_item';
        $data['items'][0]['invoice_item_id'] = $sold->id;
        $updated = app(SalesReturnService::class)->updatePending($d, $data, 1);
        $this->assertSame(3, (int) $updated->total_quantity);
        $data['document_version'] = InboundReviewVersion::token($updated);
        $data['items'][0]['return_quantity'] = 5;
        $this->rejects(fn () => app(SalesReturnService::class)->updatePending($updated, $data, 1), 'items.0.return_quantity');
        $this->assertSame(3, (int) $r->fresh()->expected_quantity);
    }

    public function test_pending_views_include_versions_reason_and_visible_edit(): void
    {
        [$d,$r] = $this->fixture();
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('key')->nullable();
            $t->string('guard_name')->nullable();
        });
        view()->share('errors', new ViewErrorBag);
        $controller = app(VoucherSalesReturnController::class);
        $html = $controller->edit($d)->render();
        $this->assertStringContainsString('name="document_version"', $html);
        $this->assertStringContainsString('name="adjustment_reason"', $html);
        file_put_contents(storage_path('app/purchase-returns-20261011/pending-edit.html'), $html);
        $changed = app(SalesReturnService::class)->updatePending($d, $this->payload($d), 1);
        $show = view('vouchers.return-from-sale.show', ['document' => $changed, 'healthCheck' => [], 'canViewSourceInvoice' => false])->render();
        $this->assertStringContainsString('سوابق اصلاح گارانتی', $show);
        $this->assertStringContainsString('اصلاح تعداد و وضعیت کالا', $show);
        $receipt = view('warehouse.inbound-queue.partials.receipt', ['receipt' => $r->load('requester', 'items.suggestedWarehouse'), 'warehouses' => Warehouse::all(), 'sourceUrl' => '/'])->render();
        $this->assertStringContainsString('name="receipt_version"', $receipt);
        file_put_contents(storage_path('app/purchase-returns-20261011/pending-receipt.html'), $receipt);
    }

    public function test_stale_draft_cannot_edit_or_cancel_a_queued_return(): void
    {
        [$d,$r] = $this->fixture();
        $stale = clone $d;
        $stale->status = 'draft';
        $this->rejects(fn () => app(SalesReturnService::class)->updateDraft($stale, $this->payload($d), 1), 'document');
        $this->rejects(fn () => app(SalesReturnService::class)->cancelDraft($stale, 1), 'document');
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_audit_failure_rolls_back_document_and_receipt_together(): void
    {
        [$d,$r] = $this->fixture();
        $token = InboundReviewVersion::token($d);
        SalesReturnDocumentRevision::creating(function () {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(SalesReturnService::class)->updatePending($d, $this->payload($d), 1);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            SalesReturnDocumentRevision::flushEventListeners();
        }$this->assertSame($token, InboundReviewVersion::token($d->fresh('items')));
        $this->assertSame(2, (int) $r->fresh()->expected_quantity);
    }

    public function test_already_materialized_new_product_is_reused_without_creating_duplicates(): void
    {
        [$d,$r] = $this->fixture();
        $category = Category::create(['name' => 'دسته', 'code' => '20']);
        $payload = ['product_name' => 'کالای نمونه', 'category_id' => $category->id, 'variant_name' => 'مشکی', 'purchase_price' => 100, 'sell_price' => 200];
        $d->items()->first()->update(['item_source' => 'new_product', 'created_product_id' => 1, 'created_variant_id' => 1, 'new_product_payload' => $payload]);
        $d = $d->fresh('items');
        $data = $this->payload($d);
        $data['items'][0]['item_source'] = 'new_product';
        $data['items'][0]['new_product_payload'] = $payload;
        $new = app(SalesReturnService::class)->updatePending($d,$data,1);
        $this->assertSame(1,(int) $new->items->first()->product_variant_id);
        $this->assertDatabaseCount('products',1);
        $this->assertDatabaseCount('product_variants',1);
    }
}
