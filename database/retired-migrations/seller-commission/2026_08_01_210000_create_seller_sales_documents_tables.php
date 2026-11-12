<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_sales_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('document_number')->unique();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedBigInteger('total_sales_amount')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['seller_id', 'period_from', 'period_to'], 'ssd_seller_period_idx');
        });
        Schema::create('seller_sales_document_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number_snapshot');
            $table->dateTime('invoice_date_snapshot');
            $table->string('customer_name_snapshot');
            $table->unsignedBigInteger('invoice_total_snapshot');
            $table->timestamps();
            $table->unique('invoice_id', 'seller_sales_document_items_invoice_unique');
            $table->unique(['seller_sales_document_id', 'invoice_id'], 'ssd_items_document_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_sales_document_items');
        Schema::dropIfExists('seller_sales_documents');
    }
};
