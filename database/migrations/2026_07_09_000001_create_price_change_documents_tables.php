<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_change_documents')) {
            Schema::create('price_change_documents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code')->nullable()->unique();
                $table->string('title')->nullable();
                $table->string('scope_type')->index();
                $table->json('scope_payload')->nullable();
                $table->string('change_type')->index();
                $table->decimal('change_value', 15, 2)->nullable();
                $table->string('rounding_mode')->default('none');
                $table->string('status')->default('draft')->index();
                $table->unsignedInteger('items_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reverted_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('price_change_document_items')) {
            Schema::create('price_change_document_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_change_document_id')->constrained('price_change_documents')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('product_variant_id')->nullable()->index()->constrained('product_variants')->nullOnDelete();
                $table->string('product_name_snapshot')->nullable();
                $table->string('variant_name_snapshot')->nullable();
                $table->string('sku_snapshot')->nullable();
                $table->unsignedBigInteger('old_price');
                $table->unsignedBigInteger('new_price');
                $table->string('change_type');
                $table->decimal('change_value', 15, 2)->nullable();
                $table->string('rounding_mode')->default('none');
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_document_items');
        Schema::dropIfExists('price_change_documents');
    }
};
