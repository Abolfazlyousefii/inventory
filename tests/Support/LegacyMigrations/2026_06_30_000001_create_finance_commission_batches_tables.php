<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_commission_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('users')->restrictOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedInteger('invoice_count')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_note')->nullable();
            $table->timestamps();

            $table->index(['visitor_id', 'from_date', 'to_date']);
            $table->index(['status', 'approved_at']);
        });

        Schema::create('finance_commission_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('finance_commission_batches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('invoice_uuid');
            $table->dateTime('invoice_date')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_mobile')->nullable();
            $table->bigInteger('invoice_total')->default(0);
            $table->string('invoice_status')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('invoice_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_commission_batch_items');
        Schema::dropIfExists('finance_commission_batches');
    }
};
