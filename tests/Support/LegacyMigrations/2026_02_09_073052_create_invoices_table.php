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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('preinvoice_order_id')->nullable()->constrained('preinvoice_orders')->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('customer_mobile')->nullable();
            $table->text('customer_address')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->unsignedBigInteger('shipping_id')->nullable();
            $table->unsignedBigInteger('shipping_price')->default(0);

            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->string('status')->default('processing'); // processing, shipped, delivered, canceled
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
