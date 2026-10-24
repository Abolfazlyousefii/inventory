<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('preinvoice_order_items', function (Blueprint $table) {

            $table->id();

            $table->foreignId('preinvoice_order_id')
                  ->constrained('preinvoice_orders')
                  ->cascadeOnDelete();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->restrictOnDelete();

            $table->foreignId('variant_id')
                  ->nullable()
                  ->constrained('product_variants')
                  ->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preinvoice_order_items');
    }
};