<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 32)->unique();
            $table->string('source_type', 40)->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('operation_key', 120);
            $table->string('source_number_snapshot', 100)->nullable()->index();
            $table->string('customer_name_snapshot')->nullable();
            $table->json('source_meta')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('expected_quantity')->default(0);
            $table->unsignedInteger('accepted_quantity')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->text('request_note')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'status'], 'wir_source_status_idx');
            $table->index(['status', 'created_at'], 'wir_status_created_idx');
            $table->unique(['source_type', 'source_id', 'operation_key'], 'wir_source_operation_unique');
        });

        Schema::create('warehouse_inbound_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('warehouse_inbound_receipts')->cascadeOnDelete();
            $table->string('source_item_type', 100)->nullable();
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot', 150)->nullable();
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('accepted_quantity')->default(0);
            $table->foreignId('suggested_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('received_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('condition', 30)->nullable();
            $table->string('reason', 60)->nullable()->index();
            $table->json('source_meta')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['receipt_id', 'product_variant_id'], 'wiri_receipt_variant_idx');
            $table->index(['source_item_type', 'source_item_id'], 'wiri_source_item_idx');
            $table->unique('stock_movement_id', 'wiri_stock_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inbound_receipt_items');
        Schema::dropIfExists('warehouse_inbound_receipts');
    }
};
