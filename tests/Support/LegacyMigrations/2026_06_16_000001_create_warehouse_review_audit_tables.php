<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_review_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preinvoice_order_id')->constrained('preinvoice_orders')->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('preinvoice_order_id', 'wrs_order_idx');
            $table->index('type', 'wrs_type_idx');
        });

        Schema::create('warehouse_review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preinvoice_order_id')->constrained('preinvoice_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('status_from', 64)->nullable();
            $table->string('status_to', 64)->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('preinvoice_order_id', 'wrl_order_idx');
            $table->index('user_id', 'wrl_user_idx');
            $table->index('action', 'wrl_action_idx');
        });

        Schema::create('warehouse_review_item_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preinvoice_order_id')->constrained('preinvoice_orders')->cascadeOnDelete();
            $table->foreignId('preinvoice_order_item_id')->nullable()->constrained('preinvoice_order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('product_code_snapshot')->nullable();
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->integer('approved_quantity')->nullable();
            $table->unsignedBigInteger('old_price')->nullable();
            $table->unsignedBigInteger('new_price')->nullable();
            $table->integer('stock_at_review')->nullable();
            $table->integer('available_stock_at_review')->nullable();
            $table->string('action', 64);
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('preinvoice_order_id', 'wril_order_idx');
            $table->index('preinvoice_order_item_id', 'wril_item_idx');
            $table->index('user_id', 'wril_user_idx');
            $table->index('action', 'wril_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_review_item_logs');
        Schema::dropIfExists('warehouse_review_logs');
        Schema::dropIfExists('warehouse_review_snapshots');
    }
};
