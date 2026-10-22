<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\CancelledInvoiceReissueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CancelledInvoiceReissueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema();
        $this->seedCancelledInvoice();
    }

    public function test_default_command_is_a_read_only_preview(): void
    {
        $this->artisan('invoices:reissue-cancelled-as-draft 01735 --seller-id=115 --invoice-id=2083 --receipt-id=358')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('preinvoice_orders')->count());
        $this->assertSame(0, DB::table('cancelled_invoice_reissues')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('document_sequences')->count());
    }

    public function test_apply_creates_an_editable_draft_without_inventory_or_financial_side_effects(): void
    {
        $this->artisan('invoices:reissue-cancelled-as-draft 01735 --seller-id=115 --invoice-id=2083 --receipt-id=358 --apply')
            ->assertExitCode(0);

        $draft = PreinvoiceOrder::query()->where('uuid', '01736')->firstOrFail();
        $this->assertSame(PreinvoiceOrder::STATUS_DRAFT, $draft->status);
        $this->assertSame(115, (int) $draft->created_by);
        $this->assertSame(115, (int) $draft->seller_id);
        $this->assertFalse((bool) $draft->is_auto_draft);
        $this->assertNull($draft->stock_frozen_until);
        $this->assertNull($draft->stock_released_at);
        $this->assertNull($draft->invoice);
        $this->assertSame(951, (int) $draft->items()->sum('quantity'));
        $this->assertSame(2, $draft->items()->count());
        $this->assertSame(1, PreinvoiceOrder::query()->createdBySeller(115)->where('status', 'draft')->count());

        $invoice = Invoice::query()->where('uuid', '01735')->firstOrFail();
        $this->assertSame('not_shipped', $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertSame('cancelled_by_finance', (string) $invoice->preinvoiceOrder->status);
        $this->assertSame('received', DB::table('warehouse_inbound_receipts')->where('id', 358)->value('status'));
        $this->assertSame(951, (int) DB::table('warehouse_inbound_receipts')->where('id', 358)->value('accepted_quantity'));
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('preinvoice_draft_reservations')->count());
        $this->assertSame(0, DB::table('invoice_payments')->count());
        $this->assertSame(2, DB::table('activity_logs')->count());

        $this->assertSame((int) $draft->id, (int) DB::table('cancelled_invoice_reissues')
            ->where('original_invoice_id', 2083)->value('replacement_preinvoice_order_id'));

        $this->assertSame(0, Invoice::query()->cancelled()->whereDoesntHave('cancelledReissue')->count());
        $this->assertSame(1, Invoice::query()->cancelled()->whereHas('cancelledReissue')->count());
    }

    public function test_reissue_is_idempotent_and_does_not_create_multiple_drafts(): void
    {
        $service = app(CancelledInvoiceReissueService::class);
        $service->createDraft('01735', 115, 2083, 358);
        $this->expectException(RuntimeException::class);
        try {
            $service->createDraft('01735', 115, 2083, 358);
        } finally {
            $this->assertSame(2, DB::table('preinvoice_orders')->count());
            $this->assertSame(1, DB::table('cancelled_invoice_reissues')->count());
        }
    }

    public function test_reissue_blocks_nonfinal_or_partial_stock_returns(): void
    {
        DB::table('warehouse_inbound_receipts')->where('id', 358)->update(['accepted_quantity' => 950]);

        $this->expectException(RuntimeException::class);
        app(CancelledInvoiceReissueService::class)->inspect('01735', 115, 2083, 358);
    }

    public function test_reissue_blocks_paid_invoices(): void
    {
        DB::table('invoice_payments')->insert(['invoice_id' => 2083, 'amount' => 1]);

        $this->expectException(RuntimeException::class);
        app(CancelledInvoiceReissueService::class)->inspect('01735', 115, 2083, 358);
    }

    public function test_reissue_requires_exact_invoice_id_and_receipt_id(): void
    {
        $service = app(CancelledInvoiceReissueService::class);
        try {
            $service->inspect('01735', 115, 2084, 358);
            $this->fail('Wrong invoice ID must fail.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->expectException(RuntimeException::class);
        $service->inspect('01735', 115, 2083, 359);
    }

    private function seedCancelledInvoice(): void
    {
        DB::table('users')->insert([
            'id' => 115,
            'name' => 'یاسین شیرمحمدلی',
            'is_active' => true,
            'can_access_erp' => true,
        ]);

        DB::table('preinvoice_orders')->insert([
            'id' => 1796,
            'uuid' => '01735',
            'status' => 'cancelled_by_finance',
            'created_by' => 115,
            'seller_id' => 115,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09120000000',
            'total_price' => 105000,
        ]);

        DB::table('invoices')->insert([
            'id' => 2083,
            'uuid' => '01735',
            'status' => 'not_shipped',
            'preinvoice_order_id' => 1796,
            'seller_id' => 115,
            'cancelled_at' => now(),
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09120000000',
            'total' => 105000,
        ]);

        DB::table('invoice_items')->insert([
            ['invoice_id' => 2083, 'product_id' => 1, 'variant_id' => 10, 'quantity' => 950, 'price' => 100, 'line_total' => 95000, 'sort_order' => 1],
            ['invoice_id' => 2083, 'product_id' => 2, 'variant_id' => 20, 'quantity' => 1, 'price' => 10000, 'line_total' => 10000, 'sort_order' => 2],
        ]);

        DB::table('warehouse_inbound_receipts')->insert([
            'id' => 358,
            'receipt_number' => 'WI-000358',
            'source_type' => 'invoice_cancel',
            'source_id' => 2083,
            'operation_key' => 'cancel',
            'status' => 'received',
            'expected_quantity' => 951,
            'accepted_quantity' => 951,
        ]);
    }

    private function schema(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->boolean('is_active');
            $t->boolean('can_access_erp');
        });
        Schema::create('preinvoice_orders', function (Blueprint $t): void {
            $t->id();
            $t->string('uuid')->unique();
            $t->unsignedBigInteger('external_order_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->timestamp('document_date')->nullable();
            $t->string('status');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_in_person')->default(false);
            $t->string('customer_name');
            $t->string('customer_mobile');
            $t->text('customer_address')->nullable();
            $t->text('description')->nullable();
            $t->text('payment_terms_note')->nullable();
            $t->unsignedBigInteger('province_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('shipping_id')->nullable();
            $t->unsignedBigInteger('shipping_price')->default(0);
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->text('discount_breakdown')->nullable();
            $t->string('invoice_discount_type')->nullable();
            $t->unsignedBigInteger('invoice_discount_value')->default(0);
            $t->unsignedBigInteger('invoice_discount_amount')->default(0);
            $t->unsignedBigInteger('product_discount_amount')->default(0);
            $t->string('discount_allocation_mode')->nullable();
            $t->unsignedBigInteger('total_price')->default(0);
            $t->timestamp('stock_frozen_until')->nullable();
            $t->timestamp('stock_released_at')->nullable();
            $t->boolean('is_auto_draft')->default(false);
            $t->timestamp('auto_saved_at')->nullable();
            $t->string('draft_token')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->string('uuid')->unique();
            $t->unsignedBigInteger('preinvoice_order_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_mobile')->nullable();
            $t->text('customer_address')->nullable();
            $t->unsignedBigInteger('province_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('shipping_id')->nullable();
            $t->unsignedBigInteger('shipping_price')->default(0);
            $t->unsignedBigInteger('discount_amount')->default(0);
            $t->text('discount_breakdown')->nullable();
            $t->string('invoice_discount_type')->nullable();
            $t->unsignedBigInteger('invoice_discount_value')->default(0);
            $t->unsignedBigInteger('invoice_discount_amount')->default(0);
            $t->unsignedBigInteger('product_discount_amount')->default(0);
            $t->string('discount_allocation_mode')->nullable();
            $t->unsignedBigInteger('total')->default(0);
            $t->string('status');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('price');
            $t->unsignedBigInteger('line_total');
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedBigInteger('line_discount_amount')->default(0);
            $t->timestamps();
        });
        Schema::create('preinvoice_order_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('preinvoice_order_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('price');
            $t->unsignedBigInteger('line_total');
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedBigInteger('line_discount_amount')->default(0);
            $t->timestamps();
        });
        Schema::create('invoice_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('amount');
        });
        Schema::create('warehouse_inbound_receipts', function (Blueprint $t): void {
            $t->id();
            $t->string('receipt_number');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->string('operation_key');
            $t->string('status');
            $t->unsignedInteger('expected_quantity');
            $t->unsignedInteger('accepted_quantity');
        });
        Schema::create('document_sequences', function (Blueprint $t): void {
            $t->id();
            $t->string('type')->unique();
            $t->unsignedInteger('last_number')->default(0);
            $t->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->text('description');
            $t->text('properties')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_movements', fn (Blueprint $t) => $t->id());
        Schema::create('preinvoice_draft_reservations', fn (Blueprint $t) => $t->id());

        $migration = require database_path('migrations/2026_10_06_000000_create_cancelled_invoice_reissues_table.php');
        $migration->up();
    }
}
