<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_return_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 32)->unique();
            $table->string('source_type', 30)->index();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('customer_id');
            $table->foreignId('invoice_id')->nullable();
            $table->string('external_invoice_number', 100)->nullable();
            $table->date('external_invoice_date')->nullable();
            $table->foreignId('default_destination_warehouse_id')->nullable();
            $table->string('return_reason', 150)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('total_quantity')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedBigInteger('total_refund_amount')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreignId('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'status'], 'srd_source_status_idx');
            $table->index(['customer_id', 'status'], 'srd_customer_status_idx');
            $table->index(['invoice_id', 'status'], 'srd_invoice_status_idx');
            $table->foreign('customer_id', 'srd_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('invoice_id', 'srd_invoice_fk')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('default_destination_warehouse_id', 'srd_def_wh_fk')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('created_by', 'srd_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'srd_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('applied_by', 'srd_applied_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'srd_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('sales_return_documents'); }
};
