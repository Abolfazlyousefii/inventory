<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('address')->nullable()->after('phone');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal_amount')->default(0)->after('total_amount');
            $table->enum('discount_type', ['amount', 'percent'])->nullable()->after('subtotal_amount');
            $table->unsignedBigInteger('discount_value')->default(0)->after('discount_type');
            $table->unsignedBigInteger('total_discount')->default(0)->after('discount_value');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->unsignedBigInteger('line_subtotal')->default(0)->after('sell_price');
            $table->enum('discount_type', ['amount', 'percent'])->nullable()->after('line_subtotal');
            $table->unsignedBigInteger('discount_value')->default(0)->after('discount_type');
            $table->unsignedBigInteger('discount_amount')->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['line_subtotal', 'discount_type', 'discount_value', 'discount_amount']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['subtotal_amount', 'discount_type', 'discount_value', 'total_discount']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
