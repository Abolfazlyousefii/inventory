<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\PreinvoiceDiscountHydrator;
use App\Services\PreinvoiceDiscountService;
use App\Support\SalesDocumentTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiscountPrecisionPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['products', 'product_variants'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->integer('product_id')->nullable();
            });
        }
        Schema::create('preinvoice_orders', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->integer('shipping_price')->default(0);
            $t->string('invoice_discount_type')->nullable();
            $t->unsignedBigInteger('invoice_discount_value')->default(0);
            foreach (['discount_amount', 'invoice_discount_amount', 'product_discount_amount', 'total_price'] as $f) {
                $t->bigInteger($f)->default(0);
            }$t->string('discount_allocation_mode')->nullable();
            $t->json('discount_breakdown')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_discount_value')->default(0);
            $t->timestamps();
        });
        Schema::create('preinvoice_order_items', function (Blueprint $t) {
            $t->id();
            foreach (['preinvoice_order_id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total', 'line_discount_amount', 'sort_order'] as $f) {
                $t->bigInteger($f)->default(0);
            }$t->timestamps();
        });
        DB::table('preinvoice_orders')->insert(['id' => 1, 'uuid' => 'QA', 'invoice_discount_value' => 10, 'shipping_price' => 100]);
        DB::table('invoices')->insert(['id' => 1, 'invoice_discount_value' => 10]);
        (require base_path('database/migrations/2026_10_16_090000_preserve_fractional_sales_discount_values.php'))->up();
        DB::table('products')->insert(['id' => 1]);
        DB::table('product_variants')->insert(['id' => 1, 'product_id' => 1]);
        DB::table('preinvoice_order_items')->insert(['id' => 1, 'preinvoice_order_id' => 1, 'product_id' => 1, 'variant_id' => 1, 'quantity' => 2, 'price' => 1000000]);
    }

    public function test_fractional_discount_contract_survives_save_reload_and_reapply(): void
    {
        $service = app(PreinvoiceDiscountService::class);
        $payload = ['invoice_discount_type' => 'percent', 'invoice_discount_value' => '2.55', 'discount_breakdown' => ['groups' => [['product_id' => 1, 'discount_type' => 'percent', 'discount_value' => '2.5']]]];
        Model::withoutEvents(fn () => $service->applyToOrder(PreinvoiceOrder::findOrFail(1), $payload));
        $order = PreinvoiceOrder::with('items')->findOrFail(1);
        $this->assertSame('2.55', $order->invoice_discount_value);
        $this->assertSame(50000, (int) $order->product_discount_amount);
        $this->assertSame(49725, (int) $order->invoice_discount_amount);
        $this->assertSame(1900375, (int) $order->total_price);
        $state = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order);
        $this->assertEquals(2.5, $state['product_groups'][1]['discount_value']);
        $this->assertEquals(2.55, $state['invoice_discount']['value']);
        $before = $order->getAttributes();
        Model::withoutEvents(fn () => $service->applyToOrder($order, ['invoice_discount_type' => $order->invoice_discount_type, 'invoice_discount_value' => $order->invoice_discount_value, 'discount_breakdown' => $order->discount_breakdown]));
        $this->assertSame(1900375, (int) $order->fresh()->total_price);
        $this->assertSame(99725, (int) SalesDocumentTotals::fromDocument($order->fresh('items'))['total_discount']);
    }

    public function test_migration_preserves_previous_values_and_refuses_lossy_rollback(): void
    {
        $this->assertEquals(10, DB::table('invoices')->value('invoice_discount_value'));
        $invoice = Invoice::findOrFail(1);
        Model::withoutEvents(fn () => $invoice->forceFill(['invoice_discount_value' => '2.55'])->save());
        $this->assertSame('2.55', $invoice->fresh()->invoice_discount_value);
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_10_16_090000_preserve_fractional_sales_discount_values.php'))->down();
    }
}
