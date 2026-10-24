<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preinvoice_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status')->default('draft'); // draft | submitted | canceled ...

            $table->string('customer_name');
            $table->string('customer_mobile', 20);
            $table->text('customer_address');

            $table->unsignedInteger('province_id');
            $table->unsignedInteger('city_id')->nullable();

            $table->unsignedInteger('shipping_id')->default(0);
            $table->unsignedBigInteger('shipping_price')->default(0);

            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_price')->default(0);

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_mobile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preinvoice_orders');
    }
};
