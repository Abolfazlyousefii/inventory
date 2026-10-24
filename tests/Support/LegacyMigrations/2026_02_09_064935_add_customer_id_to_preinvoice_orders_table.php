<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('preinvoice_orders', function (Blueprint $table) {
      $table->foreignId('customer_id')->nullable()->after('uuid')->constrained()->nullOnDelete();

      // اگر قبلا این ستون‌ها رو داری، نگهشون دار
      // اگر می‌خوای بعداً فقط از customer_id استفاده کنی، می‌تونی اینارو optional کنی
    });
  }

  public function down(): void {
    Schema::table('preinvoice_orders', function (Blueprint $table) {
      $table->dropConstrainedForeignId('customer_id');
    });
  }
};
