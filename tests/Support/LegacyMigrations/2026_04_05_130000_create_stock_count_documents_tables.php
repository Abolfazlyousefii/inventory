<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_count_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('document_date');
            $table->enum('status', ['draft', 'finalized', 'cancelled'])->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_count_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('stock_count_documents')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('system_quantity')->default(0);
            $table->integer('actual_quantity');
            $table->integer('difference_quantity')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'product_id']);
        });

        Schema::create('stock_count_document_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('stock_count_documents')->cascadeOnDelete();
            $table->string('action_type');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_document_history');
        Schema::dropIfExists('stock_count_document_items');
        Schema::dropIfExists('stock_count_documents');
    }
};
