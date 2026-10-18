<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('product_deactivation_documents')) {
            return;
        }

        Schema::create('product_deactivation_documents', function (Blueprint $table) {
            $table->id();

            $table->string('document_number')->unique();
            $table->enum('deactivation_type', ['product', 'variant']);

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->string('reason_type', 50);
            $table->text('reason_text');
            $table->text('description')->nullable();

            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            // ✅ index های با اسم کوتاه
            $table->index(
                ['deactivation_type', 'created_at'],
                'pdd_type_created_idx'
            );

            $table->index(
                ['product_id', 'variant_id'],
                'pdd_product_variant_idx'
            );

            $table->index('reason_type', 'pdd_reason_idx');

            $table->index('created_by', 'pdd_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_deactivation_documents');
    }
};