<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('preinvoice_orders')) {
            Schema::create('preinvoice_orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('status')->default('draft');

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

                $table->index('status');
                $table->index('created_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preinvoice_orders');
    }
};