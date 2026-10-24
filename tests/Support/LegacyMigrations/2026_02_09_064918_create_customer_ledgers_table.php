<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('customer_ledgers', function (Blueprint $table) {
      $table->id();
      $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

      // debit = بدهکار (افزایش بدهی)  credit = بستانکار (پرداخت/کاهش بدهی)
      $table->enum('type', ['debit', 'credit']);
      $table->unsignedBigInteger('amount');

      // لینک به سند (اختیاری)
      $table->string('reference_type')->nullable(); // مثلا PreinvoiceOrder, Invoice, Payment
      $table->unsignedBigInteger('reference_id')->nullable();

      $table->string('note')->nullable();
      $table->timestamps();

      $table->index(['reference_type', 'reference_id']);
    });
  }

  public function down(): void {
    Schema::dropIfExists('customer_ledgers');
  }
};
