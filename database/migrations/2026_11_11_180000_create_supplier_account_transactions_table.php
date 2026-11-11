<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_account_transactions', function (Blueprint $table): void {
            $table->id();
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
        Schema::dropIfExists('supplier_account_transactions');
    }
};
