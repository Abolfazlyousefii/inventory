<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_account_transactions')) {
            foreach (['id','submission_token','supplier_id','direction','amount','transaction_date','method','reference','note','created_by','created_at','updated_at'] as $column) {
                if (! Schema::hasColumn('supplier_account_transactions',$column)) {
                    throw new RuntimeException('Existing supplier account transaction schema needs review; no historical rows were changed.');
                }
            }
            if (! Schema::hasIndex('supplier_account_transactions','supplier_account_transactions_submission_token_unique')) {
                throw new RuntimeException('Existing supplier transaction idempotency index needs review.');
            }
            return;
        }
        Schema::create('supplier_account_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('submission_token', 36)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['payment', 'receipt']);
            $table->unsignedBigInteger('amount');
            $table->dateTime('transaction_date');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'transaction_date'], 'supplier_account_tx_supplier_date_idx');
        });
    }

    public function down(): void
    {
        // Financial source documents are retained as audit evidence on rollback.
    }
};
