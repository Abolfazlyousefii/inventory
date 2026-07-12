<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 32)->unique();
            $table->string('source_type', 30)->index();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('customer_id')->constrained('customers', indexName: 'srd_customer_fk')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices', indexName: 'srd_invoice_fk')->nullOnDelete();
            $table->string('external_invoice_number', 64)->nullable();
            $table->date('external_invoice_date')->nullable();
            $table->string('return_reason', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('refund_subtotal')->default(0);
            $table->unsignedBigInteger('refund_total')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'srd_creator_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'srd_updater_fk')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users', indexName: 'srd_applier_fk')->nullOnDelete();
            $table->timestamp('applied_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', indexName: 'srd_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'status'], 'srd_source_status_idx');
            $table->index(['customer_id', 'status'], 'srd_customer_status_idx');
            $table->index(['invoice_id', 'status'], 'srd_invoice_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_documents');
    }
};
