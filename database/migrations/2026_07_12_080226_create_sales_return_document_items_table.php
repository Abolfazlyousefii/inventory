<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('sales_return_documents', indexName: 'srdi_document_fk')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items', indexName: 'srdi_invoice_item_fk')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products', indexName: 'srdi_product_fk')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants', indexName: 'srdi_variant_fk')->nullOnDelete();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot', 150)->nullable();
            $table->string('item_condition', 30);
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses', indexName: 'srdi_warehouse_fk')->nullOnDelete();
            $table->unsignedInteger('sold_quantity_snapshot')->nullable();
            $table->unsignedInteger('previous_returned_quantity_snapshot')->default(0);
            $table->unsignedInteger('return_quantity');
            $table->unsignedBigInteger('unit_price_snapshot')->nullable();
            $table->unsignedBigInteger('line_discount_snapshot')->default(0);
            $table->unsignedBigInteger('allocated_invoice_discount_snapshot')->default(0);
            $table->unsignedBigInteger('refund_unit_price')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedBigInteger('purchase_price')->nullable();
            $table->unsignedBigInteger('sell_price')->nullable();
            $table->json('new_product_payload')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'sort_order'], 'srdi_doc_sort_idx');
            $table->index('invoice_item_id', 'srdi_invoice_item_idx');
            $table->index('product_variant_id', 'srdi_variant_idx');
            $table->index('destination_warehouse_id', 'srdi_warehouse_idx');
            $table->index(['document_id', 'invoice_item_id'], 'srdi_doc_invoice_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_document_items');
    }
};
