<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preinvoice_order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preinvoice_order_id')->constrained('preinvoice_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before_items')->nullable();
            $table->json('after_items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preinvoice_order_reviews');
    }
};
