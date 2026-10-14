<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('sales_return_documents');
            $table->string('status', 32)->default('pending_warehouse');
            $table->string('base_version', 64);
            $table->json('payload');
            $table->json('before_snapshot');
            $table->json('proposed_snapshot');
            $table->json('after_snapshot')->nullable();
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('warehouse_approved_by')->nullable()->constrained('users');
            $table->timestamp('warehouse_approved_at')->nullable();
            $table->text('warehouse_note')->nullable();
            $table->foreignId('finance_approved_by')->nullable()->constrained('users');
            $table->timestamp('finance_approved_at')->nullable();
            $table->text('finance_note')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'status']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_corrections');
    }
};
