<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_account_documents')) {
            foreach (['id', 'customer_id', 'supplier_id', 'type', 'amount', 'title', 'description', 'document_date', 'created_by', 'submission_token'] as $column) {
                if (! Schema::hasColumn('financial_account_documents', $column)) {
                    throw new RuntimeException('Financial document schema needs review.');
                }
            }
            if (! Schema::hasIndex('financial_account_documents', 'financial_account_documents_submission_token_unique')) {
                throw new RuntimeException('Financial document token index missing.');
            }

            return;
        }
        Schema::create('financial_account_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', ['debit', 'credit']);
            $table->unsignedBigInteger('amount');
            $table->string('title', 190);
            $table->text('description');
            $table->date('document_date');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->timestamps();
            $table->index(['customer_id', 'document_date']);
            $table->index(['supplier_id', 'document_date']);
        });
    }

    public function down(): void
    {
        // Preserve financial source documents and their ledger references on rollback.
    }
};
