<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preinvoice_draft_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('preinvoice_order_id')->nullable()->constrained('preinvoice_orders')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['token', 'product_id', 'variant_id'], 'preinvoice_draft_res_unique');
            $table->index(['token', 'user_id', 'converted_at'], 'preinvoice_draft_res_token_user_idx');
            $table->index(['expires_at', 'converted_at'], 'preinvoice_draft_res_expire_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preinvoice_draft_reservations');
    }
};
