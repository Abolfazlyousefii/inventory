<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('customers', function (Blueprint $table) {
      $table->id();

      $table->string('first_name')->nullable();
      $table->string('last_name')->nullable();
      $table->string('mobile', 20)->unique();

      $table->text('address')->nullable();
      $table->unsignedBigInteger('province_id')->nullable();
      $table->unsignedBigInteger('city_id')->nullable();

      // مانده افتتاحیه (اختیاری)
      // + یعنی بدهکار، - یعنی بستانکار
      $table->bigInteger('opening_balance')->default(0);

      $table->timestamps();

      $table->index(['last_name', 'first_name']);
      $table->index(['province_id', 'city_id']);
    });
  }

  public function down(): void {
    Schema::dropIfExists('customers');
  }
};
