<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_payment_id')->constrained('invoice_payments')->cascadeOnDelete();

            $table->string('bank_name')->nullable();
            $table->string('cheque_number')->nullable();
            $table->date('due_date')->nullable();
            $table->string('image')->nullable(); // عکس چک

            $table->string('status')->default('pending'); // pending, cleared, bounced
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
