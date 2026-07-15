<?php

namespace Tests\Unit;

use App\Http\Controllers\PreinvoiceController;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PreinvoiceReservationSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->string('sku')->unique();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedBigInteger('price')->default(0);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->boolean('is_active')->default(true);
            $table->string('variant_name');
            $table->unsignedBigInteger('sell_price')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();
        });

        Schema::create('preinvoice_orders', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status')->nullable();
            $table->string('customer_name')->nullable();
            $table->timestamp('stock_frozen_until')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('preinvoice_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preinvoice_order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('line_discount_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('preinvoice_draft_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('preinvoice_order_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_sync_uses_total_existing_order_reservation_before_checking_free_stock(): void
    {
        $product = Product::query()->create(['category_id' => 1, 'name' => 'Shapouri item', 'sku' => 'SH-1', 'stock' => 7, 'reserved' => 17, 'price' => 100]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'is_active' => true, 'variant_name' => 'Default', 'stock' => 7, 'reserved' => 17]);
        $order = PreinvoiceOrder::query()->create(['uuid' => 'test-order', 'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE]);
        PreinvoiceOrderItem::query()->create(['preinvoice_order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 17, 'price' => 100]);

        PreinvoiceDraftReservation::query()->create(['token' => '00000000-0000-0000-0000-000000000001', 'preinvoice_order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 10, 'converted_at' => now()]);
        PreinvoiceDraftReservation::query()->create(['token' => '00000000-0000-0000-0000-000000000002', 'preinvoice_order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 7, 'converted_at' => now()]);

        app(PreinvoiceController::class)->syncPreinvoiceReservations($order->fresh('items'));

        $this->assertSame(1, PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count());
        $this->assertSame(17, (int) PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->value('quantity'));
        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame(17, (int) $variant->fresh()->reserved);
    }
}
