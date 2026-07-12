<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySalesDocumentsTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_action_statuses_are_grouped_correctly_and_appear_first(): void
    {
        $seller = User::factory()->create();
        $old = $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-OLD', 'updated_at' => now()->subDays(2)]);
        $new = $this->preinvoice($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, ['uuid' => 'PI-NEW', 'updated_at' => now()]);
        $draft = $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-DRAFT']);
        $invoiceOrder = $this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid' => 'PI-INV']);
        $this->invoice($invoiceOrder, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, ['uuid' => 'INV-RETURNED', 'status_changed_at' => now()->subHour()]);

        $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'needs-action']));

        $response->assertOk()
            ->assertSee('PI-NEW')
            ->assertSee('PI-OLD')
            ->assertSee('INV-RETURNED')
            ->assertDontSee('PI-DRAFT')
            ->assertSee('نیاز به بررسی');
        $response->assertSeeInOrder(['PI-NEW', 'INV-RETURNED', 'PI-OLD']);
    }

    public function test_drafts_are_grouped_correctly_including_autosave(): void
    {
        $seller = User::factory()->create();
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-MANUAL']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-AUTO', 'is_auto_draft' => true, 'auto_saved_at' => now()]);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid' => 'PI-FINANCE']);

        $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'drafts']));

        $response->assertOk()->assertSee('PI-MANUAL')->assertSee('PI-AUTO')->assertSee('ذخیره خودکار')->assertDontSee('PI-FINANCE');
    }

    public function test_active_invoices_are_grouped_without_duplicate_preinvoice_journey(): void
    {
        $seller = User::factory()->create();
        $order = $this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid' => 'PI-CONVERTED']);
        $this->invoice($order, Invoice::STATUS_PENDING_COLLECTION, ['uuid' => 'INV-ACTIVE']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid' => 'PI-PENDING']);

        $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'documents']));

        $response->assertOk()->assertSee('INV-ACTIVE')->assertSee('PI-CONVERTED')->assertSee('PI-PENDING');
        $this->assertSame(1, substr_count($response->getContent(), 'INV-ACTIVE'));
    }

    public function test_default_tab_priority_and_explicit_tab_query(): void
    {
        $seller = User::factory()->create();
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-RETURNED']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-DRAFT']);

        $this->actingAs($seller)->get(route('preinvoice.my.index'))->assertOk()->assertSee('PI-RETURNED')->assertDontSee('PI-DRAFT');
        $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'drafts']))->assertOk()->assertSee('PI-DRAFT')->assertDontSee('PI-RETURNED');
        $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'invalid']))->assertOk()->assertSee('PI-RETURNED')->assertDontSee('PI-DRAFT');
    }

    public function test_seller_isolation_counters_and_read_only_snapshot(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-MINE']);
        $this->preinvoice($other, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-OTHER']);

        $before = $this->snapshot();
        $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'needs-action']));
        $after = $this->snapshot();

        $response->assertOk()->assertSee('PI-MINE')->assertDontSee('PI-OTHER')->assertSee('نیاز به اصلاح <span class="count">(1)</span>', false);
        $this->assertSame($before, $after);
    }

    private function preinvoice(User $seller, string $status, array $overrides = []): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create(array_merge([
            'uuid' => 'PI-' . uniqid(),
            'created_by' => $seller->id,
            'status' => $status,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09120000000',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'city_id' => 1,
            'shipping_id' => 0,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total_price' => 100000,
        ], $overrides));
    }

    private function invoice(PreinvoiceOrder $order, string $status, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'uuid' => 'INV-' . uniqid(),
            'preinvoice_order_id' => $order->id,
            'customer_name' => $order->customer_name,
            'customer_mobile' => $order->customer_mobile,
            'total' => 100000,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'status' => $status,
            'status_changed_at' => now(),
        ], $overrides));
    }

    private function snapshot(): array
    {
        return [
            'preinvoice_orders' => DB::table('preinvoice_orders')->count(),
            'preinvoice_order_items' => DB::table('preinvoice_order_items')->count(),
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
            'customer_ledgers' => DB::table('customer_ledgers')->count(),
            'product_variants_stock' => DB::table('product_variants')->sum('stock'),
            'product_variants_reserved' => DB::table('product_variants')->sum('reserved'),
            'warehouse_stocks_quantity' => DB::table('warehouse_stocks')->sum('quantity'),
        ];
    }
}
