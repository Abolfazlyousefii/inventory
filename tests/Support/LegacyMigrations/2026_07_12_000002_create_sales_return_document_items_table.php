<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_return_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id');
            $table->foreignId('invoice_item_id')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->foreignId('product_variant_id')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot', 150)->nullable();
            $table->string('barcode_snapshot', 150)->nullable();
            $table->string('item_source', 30);
            $table->string('item_condition', 30);
            $table->foreignId('destination_warehouse_id');
            $table->unsignedInteger('sold_quantity_snapshot')->nullable();
            $table->unsignedInteger('previously_returned_quantity_snapshot')->default(0);
            $table->unsignedInteger('return_quantity');
            $table->unsignedBigInteger('unit_price_snapshot')->nullable();
            $table->unsignedBigInteger('line_discount_snapshot')->default(0);
            $table->unsignedBigInteger('allocated_invoice_discount_snapshot')->default(0);
            $table->unsignedBigInteger('refund_unit_price')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedBigInteger('purchase_price')->nullable();
            $table->unsignedBigInteger('sell_price')->nullable();
            $table->json('new_product_payload')->nullable();
            $table->foreignId('created_product_id')->nullable();
            $table->foreignId('created_variant_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['document_id', 'sort_order'], 'srdi_doc_sort_idx');
            $table->index('invoice_item_id', 'srdi_invoice_item_idx');
            $table->index('product_variant_id', 'srdi_variant_idx');
            $table->index('destination_warehouse_id', 'srdi_dest_wh_idx');
            $table->index(['document_id', 'invoice_item_id'], 'srdi_doc_inv_item_idx');
            $table->unique(['document_id', 'invoice_item_id'], 'srdi_doc_inv_item_unique');
            $table->foreign('document_id', 'srdi_doc_fk')->references('id')->on('sales_return_documents')->cascadeOnDelete();
            $table->foreign('invoice_item_id', 'srdi_invoice_item_fk')->references('id')->on('invoice_items')->nullOnDelete();
            $table->foreign('product_id', 'srdi_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_variant_id', 'srdi_variant_fk')->references('id')->on('product_variants')->nullOnDelete();
            $table->foreign('destination_warehouse_id', 'srdi_dest_wh_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('created_product_id', 'srdi_created_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreign('created_variant_id', 'srdi_created_variant_fk')->references('id')->on('product_variants')->nullOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('sales_return_document_items'); }
};
